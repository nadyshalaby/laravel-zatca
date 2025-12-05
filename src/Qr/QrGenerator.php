<?php

namespace Corecave\Zatca\Qr;

use Corecave\Zatca\Contracts\InvoiceInterface;

/**
 * QR Code Generator for ZATCA Phase 2.
 *
 * Generates QR codes with 9 TLV tags as required by ZATCA.
 */
class QrGenerator
{
    protected TlvEncoder $encoder;

    public function __construct()
    {
        $this->encoder = new TlvEncoder;
    }

    /**
     * Generate QR code content for an invoice.
     *
     * @param  InvoiceInterface  $invoice  The invoice
     * @param  string  $signature  ECDSA signature (base64)
     * @param  string  $publicKey  Public key bytes
     * @param  string|null  $zatcaSignature  ZATCA certificate signature (for simplified invoices)
     * @return string Base64 encoded TLV data
     */
    public function generate(
        InvoiceInterface $invoice,
        string $signature,
        string $publicKey,
        ?string $zatcaSignature = null
    ): string {
        $tags = [
            1 => $this->getSellerName($invoice),
            2 => $invoice->getSellerVatNumber(),
            3 => $this->formatTimestamp($invoice->getIssueDate()),
            4 => $this->formatAmount($invoice->getTotalWithVat()),
            5 => $this->formatAmount($invoice->getTotalVat()),
            6 => $this->getInvoiceHash($invoice),
            7 => $this->decodeSignature($signature),
            8 => $this->formatPublicKey($publicKey),
        ];

        // Tag 9 is only for simplified invoices
        if ($invoice->isSimplified() && $zatcaSignature !== null) {
            $tags[9] = $this->decodeSignature($zatcaSignature);
        }

        return $this->encoder->encode($tags);
    }

    /**
     * Generate QR code for Phase 1 (basic 5 tags).
     */
    public function generatePhase1(InvoiceInterface $invoice): string
    {
        $tags = [
            1 => $this->getSellerName($invoice),
            2 => $invoice->getSellerVatNumber(),
            3 => $this->formatTimestamp($invoice->getIssueDate()),
            4 => $this->formatAmount($invoice->getTotalWithVat()),
            5 => $this->formatAmount($invoice->getTotalVat()),
        ];

        return $this->encoder->encode($tags);
    }

    /**
     * Get seller name (prefer Arabic name).
     */
    protected function getSellerName(InvoiceInterface $invoice): string
    {
        $seller = $invoice->getSeller();

        return $seller['name_ar'] ?? $seller['name'] ?? '';
    }

    /**
     * Format timestamp in ISO 8601 format.
     */
    protected function formatTimestamp(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Format amount to 2 decimal places.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Get invoice hash (decoded from base64).
     */
    protected function getInvoiceHash(InvoiceInterface $invoice): string
    {
        $hash = $invoice->getHash();

        if ($hash === null) {
            return '';
        }

        // Hash should be raw bytes, not base64
        return base64_decode($hash);
    }

    /**
     * Decode base64 signature to raw bytes.
     */
    protected function decodeSignature(string $signature): string
    {
        return base64_decode($signature);
    }

    /**
     * Format public key for QR code.
     */
    protected function formatPublicKey(string $publicKey): string
    {
        // If already raw bytes, return as is
        if (! str_contains($publicKey, '-----BEGIN')) {
            return $publicKey;
        }

        // Extract raw key from PEM
        $publicKey = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
            '',
            $publicKey
        );

        return base64_decode($publicKey);
    }

    /**
     * Decode QR code content.
     */
    public function decode(string $qrCode): array
    {
        return $this->encoder->decode($qrCode);
    }

    /**
     * Get human-readable QR code content.
     */
    public function toHumanReadable(string $qrCode): array
    {
        return $this->encoder->toHumanReadable($qrCode);
    }

    /**
     * Validate QR code content.
     */
    public function validate(string $qrCode, bool $isSimplified = false): array
    {
        $errors = [];

        if (! $this->encoder->isValid($qrCode)) {
            $errors[] = 'Invalid TLV encoding';

            return ['valid' => false, 'errors' => $errors];
        }

        $tags = $this->decode($qrCode);

        // Required tags for Phase 2
        $requiredTags = [1, 2, 3, 4, 5, 6, 7, 8];

        if ($isSimplified) {
            $requiredTags[] = 9;
        }

        foreach ($requiredTags as $tag) {
            if (! isset($tags[$tag]) || empty($tags[$tag])) {
                $errors[] = "Missing required tag {$tag}";
            }
        }

        // Validate VAT number format
        if (isset($tags[2]) && ! preg_match('/^3\d{14}$/', $tags[2])) {
            $errors[] = 'Invalid VAT number format in tag 2';
        }

        // Validate timestamp format
        if (isset($tags[3]) && ! $this->isValidTimestamp($tags[3])) {
            $errors[] = 'Invalid timestamp format in tag 3';
        }

        // Validate amounts
        if (isset($tags[4]) && ! is_numeric($tags[4])) {
            $errors[] = 'Invalid total amount format in tag 4';
        }

        if (isset($tags[5]) && ! is_numeric($tags[5])) {
            $errors[] = 'Invalid VAT amount format in tag 5';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'tags' => $tags,
        ];
    }

    /**
     * Check if timestamp is valid ISO 8601 format.
     */
    protected function isValidTimestamp(string $timestamp): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp);

        return $date !== false;
    }

    /**
     * Extract specific tag value from QR code.
     */
    public function getTagValue(string $qrCode, int $tag): ?string
    {
        $tags = $this->decode($qrCode);

        return $tags[$tag] ?? null;
    }
}
