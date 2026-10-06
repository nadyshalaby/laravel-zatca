<?php

namespace Corecave\Zatca\Certificate;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Exceptions\CertificateException;
use phpseclib3\Crypt\Common\PublicKey;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;

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

    /** @var array<string, mixed> */
    protected array $metadata;

    private ?X509 $decoded = null;

    /** @var array<string, mixed>|null */
    private ?array $decodedFields = null;

    public function __construct(
        CertificateOrigin $origin,
        CertificateCredentials $credentials,
        CertificateLifetime $lifetime = new CertificateLifetime
    ) {
        $this->type = $origin->type;
        $this->certificate = $credentials->certificate;
        $this->privateKey = $credentials->privateKey;
        $this->secret = $credentials->secret;
        $this->requestId = $origin->requestId;
        $this->issuedAt = $lifetime->issuedAt;
        $this->expiresAt = $lifetime->expiresAt;
        $this->active = $lifetime->active;
        $this->metadata = $origin->metadata;

        // If expiry not provided, try to extract from certificate
        if ($this->expiresAt === null) {
            $this->extractExpiryFromCertificate();
        }
    }

    /**
     * Create from ZATCA API response.
     *
     * @param  array<string, mixed>  $response  ZATCA's JSON answer
     */
    public static function fromApiResponse(array $response, string $privateKey, string $type = 'compliance'): self
    {
        $certificate = base64_decode($response['binarySecurityToken'] ?? '');
        $secret = $response['secret'] ?? '';
        $requestId = $response['requestID'] ?? null;
        $metadata = [
            'disposition_message' => $response['dispositionMessage'] ?? null,
            'token_type' => $response['tokenType'] ?? null,
        ];

        return new self(
            new CertificateOrigin($type, $requestId, $metadata),
            new CertificateCredentials($certificate, $privateKey, $secret),
            new CertificateLifetime(issuedAt: Carbon::now())
        );
    }

    /**
     * Create from stored data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            new CertificateOrigin($data['type'], $data['request_id'] ?? null, $data['metadata'] ?? []),
            new CertificateCredentials($data['certificate'], $data['private_key'], $data['secret']),
            new CertificateLifetime(
                isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : null,
                isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                $data['is_active'] ?? true
            )
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

        // Carbon 3 counts signed days from now to the expiry, so one already expired counts as expiring
        return Carbon::now()->diffInDays($this->expiresAt) <= $days;
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
        $publicKey = $this->decoded()->getPublicKey();

        if (! $publicKey instanceof PublicKey) {
            throw CertificateException::unreadable();
        }

        return $publicKey->toString('PKCS8');
    }

    /**
     * Get the raw public key bytes (for QR code).
     *
     * ZATCA Tag 8 requires the public key in DER-encoded SubjectPublicKeyInfo format.
     * This is the binary DER, not base64 or PEM.
     */
    public function getPublicKeyRaw(): string
    {
        return base64_decode($this->getRawPublicKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Convert to array for storage.
     *
     * @return array{type: string, certificate: string, private_key: string, secret: string, request_id: string|null, issued_at: string|null, expires_at: string|null, is_active: bool, metadata: array<string, mixed>}
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
        $this->expiresAt = $this->readExpiryFromCertificate();
    }

    /**
     * Read the notAfter date from the certificate, or null when the body is not a certificate at all.
     *
     * @throws CertificateException when the certificate decodes but its expiry date does not
     */
    protected function readExpiryFromCertificate(): ?Carbon
    {
        try {
            $certData = (new X509)->loadX509($this->getCertificatePem());
        } catch (\Exception $e) {
            throw CertificateException::unreadableExpiry($e);
        }

        if ($certData === false) {
            return null;
        }

        $notAfter = $certData['tbsCertificate']['validity']['notAfter'] ?? [];
        $time = $notAfter['utcTime'] ?? $notAfter['generalTime'] ?? null;

        if ($time === null) {
            return null;
        }

        // phpseclib hands an undecodable time over as false, which Carbon would read as 1970
        if ($time === false) {
            throw CertificateException::unreadableExpiry();
        }

        try {
            return Carbon::parse($time);
        } catch (InvalidFormatException $e) {
            throw CertificateException::unreadableExpiry($e);
        }
    }

    /**
     * Get certificate subject information.
     *
     * @return array<string, mixed>
     */
    public function getSubject(): array
    {
        $subject = $this->decoded()->getDN(X509::DN_ARRAY);

        return is_array($subject) ? $subject : throw CertificateException::unreadable();
    }

    /**
     * Get certificate issuer information.
     *
     * @return array<string, mixed>
     */
    public function getIssuer(): array
    {
        $issuer = $this->decodedFields()['tbsCertificate']['issuer'] ?? null;

        return is_array($issuer) ? $issuer : throw CertificateException::unreadable();
    }

    /**
     * Get certificate serial number.
     */
    public function getSerialNumber(): ?string
    {
        $serialNumber = $this->decodedFields()['tbsCertificate']['serialNumber'] ?? null;

        return $serialNumber instanceof BigInteger ? $serialNumber->toString() : throw CertificateException::unreadable();
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
        $signature = $this->decodedFields()['signature'] ?? null;

        // phpseclib returns the signature as a binary string (BIT STRING contents)
        // The first byte is the unused bits indicator - remove it
        return is_string($signature) ? substr($signature, 1) : throw CertificateException::unreadable();
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
        // Get issuer DN as string - phpseclib returns in correct format
        $issuerDn = $this->decoded()->getIssuerDN(X509::DN_STRING);

        if (! is_string($issuerDn)) {
            throw CertificateException::unreadable();
        }

        // Replace OID for domainComponent with DC if needed
        // Keep the ", " separators (do NOT remove spaces!)
        $issuerDn = str_replace('0.9.2342.19200300.100.1.25', 'DC', $issuerDn);

        // phpseclib may use "/" as separator - convert to ", "
        // Do NOT reverse the order - keep as-is from certificate
        return str_replace('/', ', ', $issuerDn);
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
        // Remove PEM headers and line breaks
        return str_replace(
            ["-----BEGIN PUBLIC KEY-----\r\n", "\r\n-----END PUBLIC KEY-----", "-----BEGIN PUBLIC KEY-----\n", "\n-----END PUBLIC KEY-----", "\r\n", "\n"],
            '',
            $this->getPublicKey()
        );
    }

    /**
     * The certificate decoded once, for every getter that reads it.
     *
     * @throws CertificateException when the text is not a certificate, with the cause where there is one
     */
    protected function decoded(): X509
    {
        if ($this->decoded === null) {
            $x509 = new X509;

            try {
                $fields = $x509->loadX509($this->getCertificatePem());
            } catch (\Exception $e) {
                throw CertificateException::unreadable($e);
            }

            $this->decodedFields = is_array($fields) ? $fields : throw CertificateException::unreadable();
            $this->decoded = $x509;
        }

        return $this->decoded;
    }

    /**
     * The decoded certificate's fields, as phpseclib maps them.
     *
     * @return array<string, mixed>
     */
    protected function decodedFields(): array
    {
        $this->decoded();

        return $this->decodedFields ?? throw CertificateException::unreadable();
    }
}
