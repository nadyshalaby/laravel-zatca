<?php

namespace Corecave\Zatca\Contracts;

use DateTimeInterface;

interface CertificateInterface
{
    /**
     * Get the certificate type ('compliance' or 'production').
     */
    public function getType(): string;

    /**
     * Get the certificate content (raw/base64).
     */
    public function getCertificate(): string;

    /**
     * Get the certificate in PEM format.
     */
    public function getCertificatePem(): string;

    /**
     * Get the private key (PEM format).
     */
    public function getPrivateKey(): string;

    /**
     * Get the certificate secret.
     */
    public function getSecret(): string;

    /**
     * Get the compliance request ID.
     */
    public function getRequestId(): ?string;

    /**
     * Get the certificate issue date.
     */
    public function getIssuedAt(): ?DateTimeInterface;

    /**
     * Get the certificate expiry date.
     */
    public function getExpiresAt(): ?DateTimeInterface;

    /**
     * Check if the certificate is active.
     */
    public function isActive(): bool;

    /**
     * Check if the certificate is expired.
     */
    public function isExpired(): bool;

    /**
     * Check if the certificate is expiring soon (within days).
     */
    public function isExpiringSoon(int $days = 30): bool;

    /**
     * Check if this is a production certificate.
     */
    public function isProduction(): bool;

    /**
     * Check if this is a compliance certificate.
     */
    public function isCompliance(): bool;

    /**
     * Get the Basic Auth credentials for API calls.
     */
    public function getAuthCredentials(): string;

    /**
     * Get the public key from the certificate (PEM format).
     */
    public function getPublicKey(): string;

    /**
     * Get the raw public key bytes (DER format) for QR code.
     */
    public function getPublicKeyRaw(): string;

    /**
     * Get certificate metadata.
     */
    public function getMetadata(): array;

    /**
     * Get the certificate's signature value (for QR code Tag 9).
     *
     * This is the signature from ZATCA's CA on the certificate itself,
     * required for simplified invoices.
     */
    public function getCertificateSignature(): string;

    /**
     * Get the formatted issuer DN string for XML signature.
     *
     * Returns issuer in RFC 2253 format with components reversed.
     */
    public function getFormattedIssuer(): string;

    /**
     * Get certificate hash for signing certificate digest.
     *
     * SHA-256 hash of the raw certificate string, base64 encoded.
     */
    public function getCertHash(): string;

    /**
     * Get raw public key in base64 format (for QR code Tag 8).
     */
    public function getRawPublicKey(): string;
}
