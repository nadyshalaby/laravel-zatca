<?php

namespace Corecave\Zatca;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Contracts\ApiClientInterface;
use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Debug\DebugDumper;
use Corecave\Zatca\Exceptions\CertificateException;
use Corecave\Zatca\Hash\HashChainManager;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Results\ClearanceResult;
use Corecave\Zatca\Results\ProcessResult;
use Corecave\Zatca\Results\ReportResult;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Corecave\Zatca\Xml\XmlValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

class ZatcaManager
{
    protected Application $app;

    protected ?ApiClientInterface $client = null;

    protected ?DebugDumper $debugDumper = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get the invoice builder.
     */
    public function invoice(): InvoiceBuilder
    {
        return $this->app->make(InvoiceBuilder::class);
    }

    /**
     * Get the certificate manager.
     */
    public function certificate(): CertificateManager
    {
        return $this->app->make(CertificateManager::class);
    }

    /**
     * Get the CSR generator.
     */
    public function csr(): CsrGenerator
    {
        return $this->app->make(CsrGenerator::class);
    }

    /**
     * Get the API client.
     */
    public function client(): ApiClientInterface
    {
        if ($this->client === null) {
            $this->client = $this->app->make(ApiClientInterface::class);

            // Set the production certificate if available
            $certificate = $this->certificate()->getActive('production');
            if ($certificate) {
                $this->client->setCertificate($certificate);
            }
        }

        return $this->client;
    }

    /**
     * Get the UBL generator.
     */
    public function xml(): UblGenerator
    {
        return $this->app->make(UblGenerator::class);
    }

    /**
     * Get the XML signer.
     */
    public function signer(): XmlSigner
    {
        return $this->app->make(XmlSigner::class);
    }

    /**
     * Get the XML validator.
     */
    public function validator(): XmlValidator
    {
        return $this->app->make(XmlValidator::class);
    }

    /**
     * Get the QR code generator.
     */
    public function qr(): QrGenerator
    {
        return $this->app->make(QrGenerator::class);
    }

    /**
     * Get the hash chain manager.
     */
    public function hashChain(): HashChainManager
    {
        return $this->app->make(HashChainManager::class);
    }

    /**
     * Get the debug dumper.
     */
    public function debug(): DebugDumper
    {
        if ($this->debugDumper === null) {
            $this->debugDumper = $this->app->make(DebugDumper::class);
        }

        return $this->debugDumper;
    }

    /**
     * Report a simplified invoice (B2C).
     */
    public function report(InvoiceInterface $invoice): ReportResult
    {
        $certificate = $this->certificate()->getActive('production');

        if (! $certificate) {
            throw CertificateException::notFound('production');
        }

        // Generate XML
        $xml = $this->xml()->generate($invoice);

        // Debug: dump unsigned XML
        $this->debug()->dumpUnsignedXml($xml, $invoice->getInvoiceNumber());

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Debug: dump hash
        $this->debug()->dumpHash($hash, $invoice->getInvoiceNumber());

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        // Generate QR code (per ZATCA spec):
        // Tag 7 = SignatureValue (base64 string)
        // Tag 8 = Public Key (raw DER bytes)
        // Tag 9 = Certificate Signature (raw bytes from X.509 cert)
        $signatureValue = $this->signer()->getSignatureValue($signedXml);
        $publicKeyDer = $certificate->getPublicKeyRaw();
        $certificateSignature = $certificate->getCertificateSignature();
        $qrCode = $this->qr()->generate($invoice, $signatureValue, $publicKeyDer, $certificateSignature);

        // Debug: dump QR code
        $this->debug()->dumpQrCode($qrCode, $invoice->getInvoiceNumber());

        // Add QR code to XML
        $signedXml = $this->xml()->addQrCode($signedXml, $qrCode);

        // Debug: dump signed XML (with QR code)
        $this->debug()->dumpSignedXml($signedXml, $invoice->getInvoiceNumber());

        // Submit to ZATCA
        $this->client()->setCertificate($certificate);
        $response = $this->client()->reportInvoice($signedXml, $hash, $invoice->getUuid());

        return new ReportResult($invoice, $signedXml, $qrCode, $response);
    }

    /**
     * Clear a standard invoice (B2B).
     */
    public function clear(InvoiceInterface $invoice): ClearanceResult
    {
        $certificate = $this->certificate()->getActive('production');

        if (! $certificate) {
            throw CertificateException::notFound('production');
        }

        // Generate XML
        $xml = $this->xml()->generate($invoice);

        // Debug: dump unsigned XML
        $this->debug()->dumpUnsignedXml($xml, $invoice->getInvoiceNumber());

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Debug: dump hash
        $this->debug()->dumpHash($hash, $invoice->getInvoiceNumber());

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        // Debug: dump signed XML (before clearance)
        $this->debug()->dumpSignedXml($signedXml, $invoice->getInvoiceNumber());

        // Submit to ZATCA for clearance
        $this->client()->setCertificate($certificate);
        $response = $this->client()->clearInvoice($signedXml, $hash, $invoice->getUuid());

        // Extract cleared XML and QR code from response
        $clearedXml = $response['clearedInvoice'] ?? $signedXml;
        $qrCode = $this->xml()->extractQrCode($clearedXml);

        // Debug: dump cleared XML and QR code from ZATCA
        if ($clearedXml !== $signedXml) {
            $this->debug()->dumpSignedXml($clearedXml, $invoice->getInvoiceNumber().'_cleared');
        }
        if ($qrCode) {
            $this->debug()->dumpQrCode($qrCode, $invoice->getInvoiceNumber());
        }

        return new ClearanceResult($invoice, $clearedXml, $qrCode, $response);
    }

    /**
     * Process an invoice (automatically determines report or clear).
     */
    public function process(InvoiceInterface $invoice): ProcessResult
    {
        if ($invoice->isSimplified()) {
            $result = $this->report($invoice);

            return new ProcessResult($invoice, $result, 'reported');
        }

        $result = $this->clear($invoice);

        return new ProcessResult($invoice, $result, 'cleared');
    }

    /**
     * Generate XML for an invoice.
     */
    public function generateXml(InvoiceInterface $invoice): string
    {
        return $this->xml()->generate($invoice);
    }

    /**
     * Sign XML with the production certificate.
     */
    public function signXml(string $xml): string
    {
        $certificate = $this->certificate()->getActive('production');

        if (! $certificate) {
            throw CertificateException::notFound('production');
        }

        return $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );
    }

    /**
     * Validate XML against ZATCA schema.
     */
    public function validateXml(string $xml): bool
    {
        return $this->validator()->validate($xml);
    }

    /**
     * Generate QR code for an invoice.
     *
     * @param  InvoiceInterface  $invoice  The invoice
     * @param  string  $signatureValue  Base64 encoded SignatureValue from XML signature
     * @param  string  $publicKey  Public key - raw SPKI DER bytes
     * @param  string  $certificateSignature  Raw certificate signature bytes
     */
    public function generateQrCode(
        InvoiceInterface $invoice,
        string $signatureValue,
        string $publicKey,
        string $certificateSignature
    ): string {
        return $this->qr()->generate($invoice, $signatureValue, $publicKey, $certificateSignature);
    }

    /**
     * Check if running in sandbox mode.
     */
    public function isSandbox(): bool
    {
        return config('zatca.environment') === 'sandbox';
    }

    /**
     * Get or create a fresh certificate for the current environment.
     *
     * In sandbox mode, this will automatically generate a new CSR and
     * obtain a compliance certificate if none exists or if forceNew is true.
     *
     * @param  bool  $forceNew  Force generation of a new certificate
     *
     * @throws CertificateException
     */
    public function getOrCreateCertificate(bool $forceNew = false): CertificateInterface
    {
        $certManager = $this->certificate();

        // Check for existing certificate
        if (! $forceNew) {
            $cert = $certManager->getActive('compliance') ?? $certManager->getActive('production');
            if ($cert && $cert->isActive()) {
                // Verify the certificate VAT matches config
                if ($this->certificateMatchesConfig($cert)) {
                    return $cert;
                }
                Log::info('ZATCA: Existing certificate VAT does not match config, generating new certificate');
            }
        }

        // In sandbox mode, auto-generate certificate
        if ($this->isSandbox()) {
            return $this->autoOnboard();
        }

        throw CertificateException::notFound('compliance or production');
    }

    /**
     * Automatically onboard and get a compliance certificate.
     *
     * This is primarily for sandbox mode where OTP can be any value.
     * For simulation/production, use the zatca:compliance command.
     *
     * @param  string  $otp  OTP code (default: '123456' for sandbox)
     *
     * @throws CertificateException
     */
    public function autoOnboard(string $otp = '123456'): CertificateInterface
    {
        Log::info('ZATCA: Starting auto-onboard process');

        // Generate fresh CSR with config values
        $csrGenerator = $this->csr();
        $csrConfig = config('zatca.csr');

        $csrData = $csrGenerator->generate([
            'organization' => $csrConfig['organization'],
            'organization_unit' => $csrConfig['organization_unit'],
            'common_name' => $csrConfig['common_name'],
            'vat_number' => $csrConfig['vat_number'],
            'invoice_types' => $csrConfig['invoice_types'] ?? '1100',
            'location' => $csrConfig['location'] ?? [],
            'business_category' => $csrConfig['business_category'] ?? 'Technology',
        ]);

        Log::info('ZATCA: CSR generated successfully');

        // Request compliance CSID from ZATCA
        $client = $this->app->make(ApiClientInterface::class);

        try {
            $response = $client->requestComplianceCsid($csrData['csr'], $otp);
            Log::info('ZATCA: Compliance CSID received', [
                'request_id' => $response['requestID'] ?? 'N/A',
            ]);
        } catch (\Exception $e) {
            Log::error('ZATCA: Failed to get compliance CSID', ['error' => $e->getMessage()]);
            throw CertificateException::csrGenerationFailed('Failed to get compliance CSID: '.$e->getMessage());
        }

        // Create and store certificate
        $certificate = Certificate::fromApiResponse($response, $csrData['private_key'], 'compliance');

        // Deactivate old certificates and store new one
        $certManager = $this->certificate();
        $certManager->deactivateAll('compliance');
        $certManager->store($certificate);

        Log::info('ZATCA: Compliance certificate stored successfully');

        return $certificate;
    }

    /**
     * Check if a certificate's embedded VAT matches the config VAT.
     */
    protected function certificateMatchesConfig(CertificateInterface $cert): bool
    {
        try {
            $configVat = config('zatca.csr.vat_number');
            if (! $configVat) {
                return true; // No config VAT to check against
            }

            $x509 = new \phpseclib3\File\X509;
            $certData = $x509->loadX509($cert->getCertificatePem());

            // Find VAT in Subject Alternative Name extension
            if (isset($certData['tbsCertificate']['extensions'])) {
                foreach ($certData['tbsCertificate']['extensions'] as $ext) {
                    if (isset($ext['extnId']) && $ext['extnId'] === 'id-ce-subjectAltName') {
                        foreach ($ext['extnValue'] as $san) {
                            if (isset($san['directoryName']['rdnSequence'])) {
                                foreach ($san['directoryName']['rdnSequence'] as $rdn) {
                                    foreach ($rdn as $attr) {
                                        // OID for UID (VAT Number)
                                        if ($attr['type'] === '0.9.2342.19200300.100.1.1') {
                                            $certVat = $attr['value']['utf8String'] ?? null;

                                            return $certVat === $configVat;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('ZATCA: Could not verify certificate VAT', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Generate a fresh certificate and sign an invoice in one call.
     *
     * This is useful for sandbox testing where you want a completely
     * fresh certificate for each invoice.
     *
     * @return array{certificate: CertificateInterface, signedXml: string, hash: string}
     */
    public function signWithFreshCertificate(InvoiceInterface $invoice): array
    {
        // Force new certificate
        $certificate = $this->getOrCreateCertificate(forceNew: true);

        // Generate XML
        $xml = $this->xml()->generate($invoice);

        // Debug: dump unsigned XML
        $this->debug()->dumpUnsignedXml($xml, $invoice->getInvoiceNumber());

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Debug: dump hash
        $this->debug()->dumpHash($hash, $invoice->getInvoiceNumber());

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        // Debug: dump signed XML
        $this->debug()->dumpSignedXml($signedXml, $invoice->getInvoiceNumber());

        return [
            'certificate' => $certificate,
            'signedXml' => $signedXml,
            'hash' => $hash,
        ];
    }
}
