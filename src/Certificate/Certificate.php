<?php

namespace Corecave\Zatca\Certificate;

use Carbon\Carbon;
use Corecave\Zatca\Contracts\CertificateInterface;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;

class Certificate implements CertificateInterface
{
    protected string $type;

    protected string $certificate;

    protected string $privateKey;

    protected string $secret;

    protected ?string $requestId;

    protected ?Carbon $issuedAt;

    protected ?Carbon $expiresAt;

    protected bool $active;

    protected array $metadata;

    public function __construct(
        string $type,
        string $certificate,
        string $privateKey,
        string $secret,
        ?string $requestId = null,
        ?Carbon $issuedAt = null,
        ?Carbon $expiresAt = null,
        bool $active = true,
        array $metadata = []
    ) {
        $this->type = $type;
        $this->certificate = $certificate;
        $this->privateKey = $privateKey;
        $this->secret = $secret;
        $this->requestId = $requestId;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
        $this->active = $active;
        $this->metadata = $metadata;

        // If expiry not provided, try to extract from certificate
        if ($this->expiresAt === null) {
            $this->extractExpiryFromCertificate();
        }
    }

    /**
     * Create from ZATCA API response.
     */
    public static function fromApiResponse(array $response, string $privateKey, string $type = 'compliance'): self
    {
        $certificate = base64_decode($response['binarySecurityToken'] ?? '');
        $secret = $response['secret'] ?? '';
        $requestId = $response['requestID'] ?? null;

        return new self(
            type: $type,
            certificate: $certificate,
            privateKey: $privateKey,
            secret: $secret,
            requestId: $requestId,
            issuedAt: Carbon::now(),
            metadata: [
                'disposition_message' => $response['dispositionMessage'] ?? null,
                'token_type' => $response['tokenType'] ?? null,
            ]
        );
    }

    /**
     * Create from stored data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            certificate: $data['certificate'],
            privateKey: $data['private_key'],
            secret: $data['secret'],
            requestId: $data['request_id'] ?? null,
            issuedAt: isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : null,
            expiresAt: isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            active: $data['is_active'] ?? true,
            metadata: $data['metadata'] ?? []
        );
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCertificate(): string
    {
        return $this->certificate;
    }

    /**
     * Get certificate in PEM format.
     */
    public function getCertificatePem(): string
    {
        if (str_contains($this->certificate, '-----BEGIN CERTIFICATE-----')) {
            return $this->certificate;
        }

        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($this->certificate, 64, "\n")
            .'-----END CERTIFICATE-----';
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getIssuedAt(): ?Carbon
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): ?Carbon
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->active && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt->diffInDays(Carbon::now()) <= $days;
    }

    public function isProduction(): bool
    {
        return $this->type === 'production';
    }

    public function isCompliance(): bool
    {
        return $this->type === 'compliance';
    }

    public function getAuthCredentials(): string
    {
        return base64_encode($this->getBinarySecurityToken().':'.$this->secret);
    }

    /**
     * Get the binarySecurityToken for ZATCA API authentication.
     *
     * ZATCA expects the exact binarySecurityToken value returned during onboarding,
     * which is base64(base64(DER certificate)).
     */
    public function getBinarySecurityToken(): string
    {
        // The certificate is stored as base64(DER) - which is after one decode of BST
        // We need to re-encode it to get back the original BST format for authentication
        return base64_encode($this->certificate);
    }

    /**
     * Get certificate in format suitable for API authentication.
     *
     * @deprecated Use getBinarySecurityToken() instead
     */
    public function getCertificateForAuth(): string
    {
        return $this->getBinarySecurityToken();
    }

    public function getPublicKey(): string
    {
        try {
            $x509 = new X509;
            $x509->loadX509($this->getCertificatePem());

            return $x509->getPublicKey()->toString('PKCS8');
        } catch (\Exception $e) {
            // Fallback: derive from private key
            $key = EC::loadPrivateKey($this->privateKey);

            return $key->getPublicKey()->toString('PKCS8');
        }
    }

    /**
     * Get the raw public key bytes (for QR code).
     *
     * ZATCA Tag 8 requires the public key in DER-encoded SubjectPublicKeyInfo format.
     * This is the binary DER, not base64 or PEM.
     */
    public function getPublicKeyRaw(): string
    {
        try {
            $x509 = new X509;
            $x509->loadX509($this->getCertificatePem());

            // Get PKCS8 PEM and extract DER bytes
            $pemKey = $x509->getPublicKey()->toString('PKCS8');

            // Extract DER from PEM
            $pemKey = str_replace(
                ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
                '',
                $pemKey
            );

            return base64_decode($pemKey);
        } catch (\Exception $e) {
            // Fallback: derive from private key
            try {
                $key = EC::loadPrivateKey($this->privateKey);
                $pemKey = $key->getPublicKey()->toString('PKCS8');

                $pemKey = str_replace(
                    ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
                    '',
                    $pemKey
                );

                return base64_decode($pemKey);
            } catch (\Exception $e) {
                return '';
            }
        }
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'certificate' => $this->certificate,
            'private_key' => $this->privateKey,
            'secret' => $this->secret,
            'request_id' => $this->requestId,
            'issued_at' => $this->issuedAt?->toDateTimeString(),
            'expires_at' => $this->expiresAt?->toDateTimeString(),
            'is_active' => $this->active,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Extract expiry date from the certificate.
     */
    protected function extractExpiryFromCertificate(): void
    {
        try {
            $x509 = new X509;
            $certData = $x509->loadX509($this->getCertificatePem());

            if ($certData && isset($certData['tbsCertificate']['validity']['notAfter'])) {
                $notAfter = $certData['tbsCertificate']['validity']['notAfter'];

                if (isset($notAfter['utcTime'])) {
                    $this->expiresAt = Carbon::parse($notAfter['utcTime']);
                } elseif (isset($notAfter['generalTime'])) {
                    $this->expiresAt = Carbon::parse($notAfter['generalTime']);
                }
            }
        } catch (\Exception $e) {
            // Could not extract expiry, leave as null
        }
    }

    /**
     * Get certificate subject information.
     */
    public function getSubject(): array
    {
        try {
            $x509 = new X509;
            $x509->loadX509($this->getCertificatePem());

            return $x509->getDN(X509::DN_HASH);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get certificate issuer information.
     */
    public function getIssuer(): array
    {
        try {
            $x509 = new X509;
            $certData = $x509->loadX509($this->getCertificatePem());

            if ($certData && isset($certData['tbsCertificate']['issuer'])) {
                return $certData['tbsCertificate']['issuer'];
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get certificate serial number.
     */
    public function getSerialNumber(): ?string
    {
        try {
            $x509 = new X509;
            $certData = $x509->loadX509($this->getCertificatePem());

            if ($certData && isset($certData['tbsCertificate']['serialNumber'])) {
                return $certData['tbsCertificate']['serialNumber']->toString();
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the certificate's signature value (for QR code Tag 9).
     *
     * This extracts the signature value from the X.509 certificate itself.
     * The signature is the ECDSA signature made by ZATCA's CA when signing
     * the certificate.
     *
     * ZATCA requires this as raw DER with the first byte (unused bits indicator) removed.
     */
    public function getCertificateSignature(): string
    {
        try {
            $x509 = new X509;
            $certData = $x509->loadX509($this->getCertificatePem());

            if ($certData && isset($certData['signature'])) {
                // phpseclib returns the signature as a binary string (BIT STRING contents)
                // The first byte is the unused bits indicator - remove it
                return substr($certData['signature'], 1);
            }

            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get the formatted issuer DN string for XML signature.
     *
     * ZATCA requires the issuer DN in RFC 4514 format with ", " separators.
     * The Python SDK uses: cert.issuer.rfc4514_string() and joins with ", ".
     *
     * Example: CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local
     *
     * IMPORTANT: Do NOT reverse the order or remove spaces after commas!
     */
    public function getFormattedIssuer(): string
    {
        try {
            $x509 = new X509;
            $x509->loadX509($this->getCertificatePem());

            // Get issuer DN as string - phpseclib returns in correct format
            $issuerDn = $x509->getIssuerDN(X509::DN_STRING);

            // Replace OID for domainComponent with DC if needed
            // Keep the ", " separators (do NOT remove spaces!)
            $issuerDn = str_replace('0.9.2342.19200300.100.1.25', 'DC', $issuerDn);

            // phpseclib may use "/" as separator - convert to ", "
            if (str_contains($issuerDn, '/')) {
                $issuerDn = str_replace('/', ', ', $issuerDn);
            }

            // Ensure ", " separator format (comma + space)
            // Do NOT reverse the order - keep as-is from certificate
            return $issuerDn;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get certificate hash for signing certificate digest.
     *
     * ZATCA requires SHA-256 hash of the raw certificate string (PEM body),
     * base64 encoded.
     */
    public function getCertHash(): string
    {
        // Get certificate content without PEM headers
        $certContent = $this->certificate;

        // If stored with PEM headers, extract just the base64 content
        if (str_contains($certContent, '-----BEGIN CERTIFICATE-----')) {
            $certContent = str_replace(
                ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
                '',
                $certContent
            );
        }

        // Hash the raw certificate string (base64 encoded DER), NOT the decoded DER bytes
        return base64_encode(hash('sha256', $certContent, false));
    }

    /**
     * Get raw public key in base64 format (for QR code Tag 8).
     *
     * Returns the public key in PKCS8 format without PEM headers.
     */
    public function getRawPublicKey(): string
    {
        try {
            $x509 = new X509;
            $x509->loadX509($this->getCertificatePem());

            $pemKey = $x509->getPublicKey()->toString('PKCS8');

            // Remove PEM headers and line breaks
            return str_replace(
                ["-----BEGIN PUBLIC KEY-----\r\n", "\r\n-----END PUBLIC KEY-----", "-----BEGIN PUBLIC KEY-----\n", "\n-----END PUBLIC KEY-----", "\r\n", "\n"],
                '',
                $pemKey
            );
        } catch (\Exception $e) {
            // Fallback: derive from private key
            try {
                $key = EC::loadPrivateKey($this->privateKey);
                $pemKey = $key->getPublicKey()->toString('PKCS8');

                return str_replace(
                    ["-----BEGIN PUBLIC KEY-----\r\n", "\r\n-----END PUBLIC KEY-----", "-----BEGIN PUBLIC KEY-----\n", "\n-----END PUBLIC KEY-----", "\r\n", "\n"],
                    '',
                    $pemKey
                );
            } catch (\Exception $e) {
                return '';
            }
        }
    }
}
