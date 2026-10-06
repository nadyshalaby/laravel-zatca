<?php

namespace Corecave\Zatca\Certificate;

use Corecave\Zatca\Exceptions\CertificateException;
use OpenSSLAsymmetricKey;

class CsrGenerator
{
    protected array $config;

    /**
     * ZATCA Certificate Template Name.
     */
    private const ZATCA_TEMPLATE_NAME = 'ZATCA-Code-Signing';

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
            return $this->generateKeyAndRequest($config);
        } catch (\Exception $e) {
            throw CertificateException::csrGenerationFailed($e->getMessage(), $e);
        }
    }

    /**
     * Generate the EC key pair and the CSR signed with it, through a temporary OpenSSL config file.
     *
     * @param  array<string, mixed>  $config
     * @return array{csr: string, private_key: string, public_key: string}
     *
     * @throws CertificateException
     */
    protected function generateKeyAndRequest(array $config): array
    {
        // Create OpenSSL config for ZATCA-compliant CSR
        $opensslConfig = $this->buildOpensslConfig($config);

        // Write temporary config file, removed however the request ends
        $configFile = tempnam(sys_get_temp_dir(), 'zatca_csr_');
        file_put_contents($configFile, $opensslConfig);

        try {
            $privateKeyResource = $this->createPrivateKey($configFile);

            // Export private key
            openssl_pkey_export($privateKeyResource, $privateKey);

            // Get public key
            $keyDetails = openssl_pkey_get_details($privateKeyResource);

            return [
                'csr' => $this->createCsr($config, $privateKeyResource, $configFile),
                'private_key' => $privateKey,
                'public_key' => $keyDetails['key'],
            ];
        } finally {
            if (is_file($configFile)) {
                unlink($configFile);
            }
        }
    }

    /**
     * Generate an EC private key on the secp256k1 curve ZATCA requires.
     *
     * @throws CertificateException
     */
    protected function createPrivateKey(string $configFile): OpenSSLAsymmetricKey
    {
        $privateKeyResource = openssl_pkey_new([
            'config' => $configFile,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ]);

        if ($privateKeyResource === false) {
            throw CertificateException::withContext('Failed to generate private key: '.openssl_error_string());
        }

        return $privateKeyResource;
    }

    /**
     * Generate the CSR for the configured subject, signed with the private key, as PEM.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws CertificateException
     */
    protected function createCsr(array $config, OpenSSLAsymmetricKey $privateKey, string $configFile): string
    {
        // Build Distinguished Name
        $dn = $this->buildDistinguishedName($config);

        // Generate CSR with custom config
        $csrResource = openssl_csr_new(
            $dn,
            $privateKey,
            [
                'config' => $configFile,
                'req_extensions' => 'req_ext',
                'digest_alg' => 'sha256',
            ]
        );

        if ($csrResource === false) {
            throw CertificateException::withContext('Failed to generate CSR: '.openssl_error_string());
        }

        // Export CSR
        openssl_csr_export($csrResource, $csr);

        return $csr;
    }

    /**
     * Build OpenSSL configuration for ZATCA-compliant CSR.
     */
    protected function buildOpensslConfig(array $config): string
    {
        return $this->opensslRequestSections($config)."\n\n".$this->opensslAltNamesSection($config);
    }

    /**
     * The OpenSSL config sections for the request, its subject and its extensions.
     *
     * @param  array<string, mixed>  $config
     */
    protected function opensslRequestSections(array $config): string
    {
        $templateName = self::ZATCA_TEMPLATE_NAME;

        return <<<EOT
# ZATCA CSR Configuration
oid_section = OIDs

[OIDs]
certificateTemplateName = 1.3.6.1.4.1.311.20.2

[req]
default_bits = 2048
prompt = no
default_md = sha256
req_extensions = req_ext
distinguished_name = dn

[dn]
C = SA
O = {$config['organization']}
OU = {$config['organization_unit']}
CN = {$config['common_name']}

[req_ext]
certificateTemplateName = ASN1:PRINTABLESTRING:{$templateName}
subjectAltName = dirName:alt_names
EOT;
    }

    /**
     * The OpenSSL config section for the subject alternative names ZATCA reads the EGS details from.
     *
     * @param  array<string, mixed>  $config
     */
    protected function opensslAltNamesSection(array $config): string
    {
        $serialNumber = $config['serial_number'] ?? '1-TST|2-TST|3-'.$this->generateUuid();
        $vatNumber = $config['vat_number'];
        $invoiceTypes = $config['invoice_types'] ?? '1100';
        $location = $config['location'] ?? [];
        $address = is_array($location)
            ? ($location['city'] ?? 'Riyadh')
            : $location;
        $businessCategory = $config['business_category'] ?? 'Technology';

        return <<<EOT
[alt_names]
SN = {$serialNumber}
UID = {$vatNumber}
title = {$invoiceTypes}
registeredAddress = {$address}
businessCategory = {$businessCategory}
EOT;
    }

    /**
     * Generate a CSR whose EGS serial number is built from the given parts.
     *
     * @return array{csr: string, private_key: string, public_key: string, serial_number: string}
     *
     * @throws CertificateException
     */
    public function generateWithSerial(EgsSerialNumber $serialNumber, ?array $config = null): array
    {
        $config = $config ?? $this->config;
        $config['serial_number'] = (string) $serialNumber;

        $result = $this->generate($config);
        $result['serial_number'] = $config['serial_number'];

        return $result;
    }

    /**
     * Build the Distinguished Name (DN) for the CSR.
     */
    protected function buildDistinguishedName(array $config): array
    {
        return [
            'countryName' => 'SA',
            'organizationName' => $config['organization'],
            'organizationalUnitName' => $config['organization_unit'],
            'commonName' => $config['common_name'],
        ];
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
     *
     * ZATCA requires the full PEM-formatted CSR to be base64 encoded,
     * including the BEGIN/END headers.
     */
    public function encodeForApi(string $csr): string
    {
        return base64_encode($csr);
    }

    /**
     * Parse and extract information from a CSR.
     */
    public function parse(string $csr): array
    {
        $csrResource = openssl_csr_get_subject($csr, true);

        if ($csrResource === false) {
            throw CertificateException::csrGenerationFailed('Invalid CSR format');
        }

        $publicKey = openssl_csr_get_public_key($csr);
        $publicKeyDetails = openssl_pkey_get_details($publicKey);

        return [
            'subject' => $csrResource,
            'public_key' => $publicKeyDetails['key'] ?? null,
        ];
    }

    /**
     * Verify that a private key matches a CSR.
     */
    public function verifyKeyPair(string $csr, string $privateKey): bool
    {
        try {
            $csrPublicKey = openssl_csr_get_public_key($csr);
            if ($csrPublicKey === false) {
                return false;
            }

            $csrKeyDetails = openssl_pkey_get_details($csrPublicKey);
            $privateKeyResource = openssl_pkey_get_private($privateKey);

            if ($privateKeyResource === false) {
                return false;
            }

            $privateKeyDetails = openssl_pkey_get_details($privateKeyResource);

            return $csrKeyDetails['key'] === $privateKeyDetails['key'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate a UUID v4.
     */
    protected function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000,
            mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF)
        );
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
