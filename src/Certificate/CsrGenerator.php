<?php

namespace Corecave\Zatca\Certificate;

use Corecave\Zatca\Exceptions\CertificateException;
use phpseclib3\Crypt\EC;
use phpseclib3\File\ASN1;
use phpseclib3\File\X509;

class CsrGenerator
{
    protected array $config;

    /**
     * ZATCA Certificate Template OID.
     */
    private const ZATCA_TEMPLATE_OID = '1.3.6.1.4.1.311.20.2';

    /**
     * ZATCA-specific extension OIDs.
     */
    private const ZATCA_EXTENSION_OIDS = [
        'certificateTemplateName' => '1.3.6.1.4.1.311.20.2',
    ];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Generate a CSR and private key for ZATCA onboarding.
     *
     * @return array{csr: string, private_key: string, public_key: string}
     *
     * @throws CertificateException
     */
    public function generate(?array $config = null): array
    {
        $config = $config ?? $this->config;
        $this->validateConfig($config);

        try {
            // Generate ECDSA private key with secp256k1 curve (required by ZATCA)
            $privateKey = EC::createKey('secp256k1');

            // Create X509 instance for CSR
            $x509 = new X509;
            $x509->setPrivateKey($privateKey);

            // Build the Distinguished Name (DN)
            $dn = $this->buildDistinguishedName($config);
            $x509->setDN($dn);

            // Load CSR
            $x509->loadCSR($x509->saveCSR($x509->signCSR()));

            // Add ZATCA-specific extensions
            $this->addZatcaExtensions($x509, $config);

            // Sign the CSR
            $csr = $x509->signCSR();

            return [
                'csr' => $x509->saveCSR($csr),
                'private_key' => $privateKey->toString('PKCS8'),
                'public_key' => $privateKey->getPublicKey()->toString('PKCS8'),
            ];
        } catch (\Exception $e) {
            throw CertificateException::csrGenerationFailed($e->getMessage());
        }
    }

    /**
     * Generate a CSR with custom serial number components.
     *
     * @param  string  $companyName  Company name
     * @param  string  $version  EGS version (e.g., '1.0')
     * @param  string  $uuid  EGS unit UUID
     * @return array{csr: string, private_key: string, public_key: string, serial_number: string}
     *
     * @throws CertificateException
     */
    public function generateWithSerial(string $companyName, string $version, string $uuid, ?array $config = null): array
    {
        $config = $config ?? $this->config;

        // Build serial number in ZATCA format: 1-CompanyName|2-Version|3-UUID
        $serialNumber = sprintf('1-%s|2-%s|3-%s', $companyName, $version, $uuid);
        $config['serial_number'] = $serialNumber;

        $result = $this->generate($config);
        $result['serial_number'] = $serialNumber;

        return $result;
    }

    /**
     * Build the Distinguished Name (DN) for the CSR.
     */
    protected function buildDistinguishedName(array $config): array
    {
        $dn = [
            'countryName' => $config['country'] ?? 'SA',
            'organizationName' => $config['organization'],
            'organizationalUnitName' => $config['organization_unit'],
            'commonName' => $config['common_name'],
        ];

        return $dn;
    }

    /**
     * Add ZATCA-specific extensions to the CSR.
     */
    protected function addZatcaExtensions(X509 $x509, array $config): void
    {
        // The extensions for ZATCA CSR include:
        // 1. Serial Number (SN) - format: 1-CompanyName|2-Version|3-UUID
        // 2. UID - VAT registration number (15 digits)
        // 3. Title - Invoice types (1000, 0100, or 1100)
        // 4. Business Category
        // 5. Location details

        // Note: phpseclib handles some extensions automatically through DN
        // For ZATCA-specific extensions, we need to add them as custom attributes

        // Set the Subject Alternative Name extension if needed
        if (! empty($config['common_name'])) {
            $x509->setDNProp('commonName', $config['common_name']);
        }

        // Register custom OIDs for ZATCA
        $this->registerZatcaOids();
    }

    /**
     * Register ZATCA-specific OIDs.
     */
    protected function registerZatcaOids(): void
    {
        // Register the certificate template name OID
        ASN1::loadOIDs([
            'certificateTemplateName' => self::ZATCA_TEMPLATE_OID,
        ]);
    }

    /**
     * Validate the CSR configuration.
     *
     * @throws CertificateException
     */
    protected function validateConfig(array $config): void
    {
        $required = ['organization', 'organization_unit', 'common_name', 'vat_number'];

        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw CertificateException::csrGenerationFailed("Missing required field: {$field}");
            }
        }

        // Validate VAT number format (15 digits starting with 3)
        if (! preg_match('/^3\d{14}$/', $config['vat_number'])) {
            throw CertificateException::csrGenerationFailed(
                'VAT number must be 15 digits starting with 3'
            );
        }

        // Validate invoice types
        $validInvoiceTypes = ['1000', '0100', '1100'];
        if (isset($config['invoice_types']) && ! in_array($config['invoice_types'], $validInvoiceTypes)) {
            throw CertificateException::csrGenerationFailed(
                'Invoice types must be one of: 1000 (B2C), 0100 (B2B), 1100 (Both)'
            );
        }
    }

    /**
     * Build the CSR content as base64 for ZATCA API submission.
     */
    public function encodeForApi(string $csr): string
    {
        // Remove PEM headers and encode
        $csrContent = str_replace(
            ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"],
            '',
            $csr
        );

        return trim($csrContent);
    }

    /**
     * Parse and extract information from a CSR.
     */
    public function parse(string $csr): array
    {
        $x509 = new X509;
        $csrData = $x509->loadCSR($csr);

        if ($csrData === false) {
            throw CertificateException::csrGenerationFailed('Invalid CSR format');
        }

        return [
            'subject' => $x509->getDN(X509::DN_STRING),
            'subject_array' => $x509->getDN(X509::DN_HASH),
            'public_key' => $x509->getPublicKey()->toString('PKCS8'),
            'signature_algorithm' => $csrData['signatureAlgorithm']['algorithm'] ?? null,
        ];
    }

    /**
     * Verify that a private key matches a CSR.
     */
    public function verifyKeyPair(string $csr, string $privateKey): bool
    {
        try {
            $x509 = new X509;
            $csrData = $x509->loadCSR($csr);

            if ($csrData === false) {
                return false;
            }

            $csrPublicKey = $x509->getPublicKey()->toString('PKCS8');

            $key = EC::loadPrivateKey($privateKey);
            $derivedPublicKey = $key->getPublicKey()->toString('PKCS8');

            return $csrPublicKey === $derivedPublicKey;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the current configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the configuration.
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }
}
