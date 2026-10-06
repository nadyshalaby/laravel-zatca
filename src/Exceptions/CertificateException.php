<?php

namespace Corecave\Zatca\Exceptions;

class CertificateException extends ZatcaException
{
    /**
     * Certificate not found.
     */
    public static function notFound(string $type = 'production'): static
    {
        return new static("No active {$type} certificate found. Please complete the onboarding process first.");
    }

    /**
     * Certificate expired.
     */
    public static function expired(string $type = 'production'): static
    {
        return new static("The {$type} certificate has expired. Please renew your certificate.");
    }

    /**
     * Certificate generation failed.
     */
    public static function generationFailed(string $reason): static
    {
        return new static("Failed to generate certificate: {$reason}");
    }

    /**
     * CSR generation failed.
     */
    public static function csrGenerationFailed(string $reason, ?\Throwable $cause = null): static
    {
        return new static("Failed to generate CSR: {$reason}", 0, $cause);
    }

    /**
     * ZATCA did not hand a compliance CSID over for the request.
     */
    public static function csidRequestFailed(\Throwable $cause): static
    {
        return new static("Failed to get compliance CSID: {$cause->getMessage()}", 0, $cause);
    }

    /**
     * The text is not a certificate that can be read.
     */
    public static function unreadable(?\Throwable $cause = null): static
    {
        return new static('Invalid certificate: it cannot be read', 0, $cause);
    }

    /**
     * The certificate decodes, but its expiry date does not.
     */
    public static function unreadableExpiry(?\Throwable $cause = null): static
    {
        return new static('Invalid certificate: its expiry date cannot be read', 0, $cause);
    }

    /**
     * Invalid certificate.
     */
    public static function invalid(string $reason): static
    {
        return new static("Invalid certificate: {$reason}");
    }

    /**
     * Private key mismatch.
     */
    public static function keyMismatch(): static
    {
        return new static('The private key does not match the certificate.');
    }
}
