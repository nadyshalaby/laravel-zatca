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
     * Get the certificate content (PEM format).
     */
    public function getCertificate(): string;

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
     * Get the public key from the certificate.
     */
    public function getPublicKey(): string;

    /**
     * Get certificate metadata.
     */
    public function getMetadata(): array;
}
