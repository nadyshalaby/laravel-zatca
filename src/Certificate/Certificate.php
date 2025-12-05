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
            ."-----END CERTIFICATE-----";
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
        return base64_encode($this->getCertificateForAuth().':'.$this->secret);
    }

    /**
     * Get certificate in format suitable for API authentication.
     */
    public function getCertificateForAuth(): string
    {
        // Remove PEM headers for authentication
        return str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $this->getCertificatePem()
        );
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
     */
    public function getPublicKeyRaw(): string
    {
        try {
            $key = EC::loadPrivateKey($this->privateKey);
            $publicKey = $key->getPublicKey();

            // Get the raw point coordinates
            return $publicKey->toString('Raw');
        } catch (\Exception $e) {
            return '';
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
}
