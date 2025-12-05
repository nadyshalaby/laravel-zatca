<?php

namespace Corecave\Zatca;

use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Contracts\ApiClientInterface;
use Corecave\Zatca\Contracts\InvoiceInterface;
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

class ZatcaManager
{
    protected Application $app;

    protected ?ApiClientInterface $client = null;

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

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificate()
        );

        // Generate QR code
        $signature = $this->signer()->getSignatureValue($signedXml);
        $qrCode = $this->qr()->generate($invoice, $signature, $certificate->getPublicKey());

        // Add QR code to XML
        $signedXml = $this->xml()->addQrCode($signedXml, $qrCode);

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

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificate()
        );

        // Submit to ZATCA for clearance
        $this->client()->setCertificate($certificate);
        $response = $this->client()->clearInvoice($signedXml, $hash, $invoice->getUuid());

        // Extract cleared XML and QR code from response
        $clearedXml = $response['clearedInvoice'] ?? $signedXml;
        $qrCode = $this->xml()->extractQrCode($clearedXml);

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
            $certificate->getCertificate()
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
     */
    public function generateQrCode(InvoiceInterface $invoice, string $signature, string $publicKey): string
    {
        return $this->qr()->generate($invoice, $signature, $publicKey);
    }
}
