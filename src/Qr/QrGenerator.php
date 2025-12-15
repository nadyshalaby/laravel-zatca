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
     * ZATCA Phase 2 TLV tags (per ZATCA SDK reference):
     * - Tags 1-5: Text values (seller, VAT, timestamp, amounts) as UTF-8 strings
     * - Tag 6: Invoice Hash - base64 encoded string (DigestValue from XML signature)
     * - Tag 7: Digital Signature Value - base64 encoded string (SignatureValue from XML)
     * - Tag 8: ECDSA Public Key - raw SPKI DER bytes
     * - Tag 9: Certificate Signature - raw signature bytes from X.509 certificate
     *
     * NOTE: Per ZATCA SDK reference implementation, tags 6 and 7 are stored as
     * base64 STRINGS (encoded to UTF-8 bytes in TLV), while tags 8 and 9 are
     * raw binary bytes.
     *
     * @param  InvoiceInterface  $invoice  The invoice
     * @param  string  $signatureValue  Base64 encoded SignatureValue from XML signature
     * @param  string  $publicKey  Public key - raw SPKI DER bytes
     * @param  string  $certificateSignature  Raw certificate signature bytes (from X.509 cert)
     * @return string Base64 encoded TLV data
     */
    public function generate(
        InvoiceInterface $invoice,
        string $signatureValue,
        string $publicKey,
        string $certificateSignature
    ): string {
        $tags = [
            1 => $this->getSellerName($invoice),
            2 => $invoice->getSellerVatNumber(),
            3 => $this->formatTimestamp($invoice->getIssueDate()),
            4 => $this->formatAmount($invoice->getTotalWithVat()),
            5 => $this->formatAmount($invoice->getTotalVat()),
            6 => $this->getInvoiceHash($invoice),                // base64 string (DigestValue)
            7 => $signatureValue,                                 // base64 string (SignatureValue)
            8 => $this->formatPublicKey($publicKey),             // SPKI DER bytes
            9 => $certificateSignature,                          // Raw cert signature bytes
        ];

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
     * Format timestamp for ZATCA QR code.
     *
     * ZATCA requires the timestamp to match the XML IssueDate + IssueTime.
     * Format: YYYY-MM-DDTHH:MM:SS (without timezone suffix).
     *
     * Note: The "Z" suffix should NOT be added as it indicates UTC timezone,
     * but ZATCA expects the timestamp to match exactly with the XML values
     * which are formatted as date (YYYY-MM-DD) and time (HH:MM:SS) separately.
     */
    protected function formatTimestamp(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    /**
     * Format amount to 2 decimal places.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Get invoice hash for QR code Tag 6 as raw bytes.
     *
     * ZATCA Phase 2 requires Tag 6 to contain the raw SHA-256 hash bytes,
     * NOT the base64-encoded string. The invoice stores the hash as base64,
     * so we decode it to get the raw 32-byte hash.
     */
    protected function getInvoiceHashRaw(InvoiceInterface $invoice): string
    {
        $hash = $invoice->getHash();

        if ($hash === null) {
            return '';
        }

        // Decode from base64 to get raw SHA-256 bytes (32 bytes)
        return base64_decode($hash);
    }

    /**
     * Get invoice hash for QR code Tag 6.
     *
     * Returns the base64 encoded hash string as stored in the invoice.
     * Per ZATCA SDK reference, tag 6 contains the base64 string (not raw bytes).
     */
    protected function getInvoiceHash(InvoiceInterface $invoice): string
    {
        $hash = $invoice->getHash();

        if ($hash === null) {
            return '';
        }

        return $hash;
    }

    /**
     * Format public key for QR code.
     *
     * ZATCA expects the SPKI (SubjectPublicKeyInfo) DER-encoded public key.
     * The input should already be raw DER bytes from Certificate::getPublicKeyRaw().
     */
    protected function formatPublicKey(string $publicKey): string
    {
        // If it's a PEM formatted key, extract the DER
        if (str_contains($publicKey, '-----BEGIN')) {
            $publicKey = str_replace(
                ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
                '',
                $publicKey
            );

            return base64_decode($publicKey);
        }

        // Already raw DER bytes
        return $publicKey;
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
