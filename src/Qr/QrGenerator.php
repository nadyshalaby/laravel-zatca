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
     * ZATCA Phase 2 TLV tags (per ZATCA official spec):
     * - Tags 1-5: Text values (seller, VAT, timestamp, amounts)
     * - Tag 6: Invoice Hash (base64 encoded DigestValue from signature)
     * - Tag 7: Digital Signature Value (base64 encoded SignatureValue)
     * - Tag 8: ECDSA Public Key (SPKI DER format bytes)
     * - Tag 9: Certificate Signature (raw signature from X.509 certificate)
     *
     * @param  InvoiceInterface  $invoice  The invoice
     * @param  QrSignature  $signature  The signature value, the public key and the certificate signature
     * @return string Base64 encoded TLV data
     */
    public function generate(InvoiceInterface $invoice, QrSignature $signature): string
    {
        $tags = [
            1 => $this->getSellerName($invoice),
            2 => $invoice->getSellerVatNumber(),
            3 => $this->formatTimestamp($invoice->getIssueDate()),
            4 => $invoice->getTotalWithVat(),
            5 => $invoice->getTotalVat(),
            6 => $this->getInvoiceHash($invoice),                // base64 DigestValue string
            7 => $signature->signatureValue,                      // base64 SignatureValue string
            8 => $this->formatPublicKey($signature->publicKey),  // SPKI DER bytes
            9 => $signature->certificateSignature,               // Raw cert signature bytes
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
            4 => $invoice->getTotalWithVat(),
            5 => $invoice->getTotalVat(),
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
     * Get invoice hash for QR code Tag 6.
     *
     * Per trusted package: Tag 6 receives the base64 encoded hash,
     * NOT raw bytes. The hash is stored as base64 on the invoice.
     */
    protected function getInvoiceHash(InvoiceInterface $invoice): string
    {
        $hash = $invoice->getHash();

        if ($hash === null) {
            return '';
        }

        // Return base64 encoded hash as-is (NOT decoded to raw bytes!)
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
     *
     * @return array<int, string>
     */
    public function decode(string $qrCode): array
    {
        return $this->encoder->decode($qrCode);
    }

    /**
     * Get human-readable QR code content.
     *
     * @return list<array{tag: int, name: string, length: int, value: string}>
     */
    public function toHumanReadable(string $qrCode): array
    {
        return $this->encoder->toHumanReadable($qrCode);
    }

    /**
     * Validate QR code content.
     *
     * @return array{valid: bool, errors: list<string>, tags?: array<int, string>}
     */
    public function validate(string $qrCode, bool $isSimplified = false): array
    {
        if (! $this->encoder->isValid($qrCode)) {
            return ['valid' => false, 'errors' => ['Invalid TLV encoding']];
        }

        $tags = $this->decode($qrCode);

        // Required tags for Phase 2
        $requiredTags = [1, 2, 3, 4, 5, 6, 7, 8];

        if ($isSimplified) {
            $requiredTags[] = 9;
        }

        $errors = [...$this->missingTagErrors($tags, $requiredTags), ...$this->tagFormatErrors($tags)];

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'tags' => $tags,
        ];
    }

    /**
     * An error for each required tag that is missing or empty.
     *
     * @param  array<int, string>  $tags
     * @param  list<int>  $requiredTags
     * @return list<string>
     */
    protected function missingTagErrors(array $tags, array $requiredTags): array
    {
        $errors = [];

        foreach ($requiredTags as $tag) {
            if (! isset($tags[$tag]) || empty($tags[$tag])) {
                $errors[] = "Missing required tag {$tag}";
            }
        }

        return $errors;
    }

    /**
     * An error for each tag whose value is not in the shape ZATCA expects: the VAT number, the timestamp and the amounts.
     *
     * @param  array<int, string>  $tags
     * @return list<string>
     */
    protected function tagFormatErrors(array $tags): array
    {
        $errors = [];

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

        return $errors;
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
