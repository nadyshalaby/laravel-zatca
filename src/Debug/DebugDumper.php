<?php

namespace Corecave\Zatca\Debug;

use Illuminate\Support\Facades\File;

/**
 * Debug dumper for ZATCA invoice processing.
 *
 * Dumps generated XML, QR codes, and hashes to files for debugging.
 */
class DebugDumper
{
    protected bool $enabled;

    protected string $basePath;

    protected array $options;

    public function __construct()
    {
        $this->enabled = config('zatca.debug.enabled', false);
        $this->basePath = storage_path(config('zatca.debug.path', 'zatca/debug'));
        $this->options = config('zatca.debug', []);
    }

    /**
     * Check if debug dumping is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable debug dumping programmatically.
     */
    public function enable(): self
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * Disable debug dumping programmatically.
     */
    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Set custom debug path.
     */
    public function setPath(string $path): self
    {
        $this->basePath = $path;

        return $this;
    }

    /**
     * Dump unsigned XML.
     */
    public function dumpUnsignedXml(string $xml, string $invoiceId): ?string
    {
        if (! $this->enabled || ! ($this->options['dump_unsigned_xml'] ?? true)) {
            return null;
        }

        return $this->dump($xml, $invoiceId, 'unsigned', 'xml');
    }

    /**
     * Dump signed XML.
     */
    public function dumpSignedXml(string $xml, string $invoiceId): ?string
    {
        if (! $this->enabled || ! ($this->options['dump_signed_xml'] ?? true)) {
            return null;
        }

        return $this->dump($xml, $invoiceId, 'signed', 'xml');
    }

    /**
     * Dump QR code data.
     */
    public function dumpQrCode(string $qrCode, string $invoiceId): ?string
    {
        if (! $this->enabled || ! ($this->options['dump_qr'] ?? true)) {
            return null;
        }

        // Dump both raw base64 and decoded info
        $this->dump($qrCode, $invoiceId, 'qr', 'txt');

        // Also dump human-readable QR info if possible
        try {
            $decoded = $this->decodeQrForDebug($qrCode);
            $this->dump($decoded, $invoiceId, 'qr_decoded', 'json');
        } catch (\Exception $e) {
            // Ignore decode errors
        }

        return $this->getFilePath($invoiceId, 'qr', 'txt');
    }

    /**
     * Dump invoice hash.
     */
    public function dumpHash(string $hash, string $invoiceId): ?string
    {
        if (! $this->enabled || ! ($this->options['dump_hash'] ?? true)) {
            return null;
        }

        $content = "Invoice Hash (Base64): {$hash}\n";
        $content .= 'Invoice Hash (Hex): '.bin2hex(base64_decode($hash))."\n";

        return $this->dump($content, $invoiceId, 'hash', 'txt');
    }

    /**
     * Dump all invoice artifacts at once.
     */
    public function dumpAll(
        string $invoiceId,
        ?string $unsignedXml = null,
        ?string $signedXml = null,
        ?string $qrCode = null,
        ?string $hash = null
    ): array {
        $files = [];

        if ($unsignedXml !== null) {
            $files['unsigned_xml'] = $this->dumpUnsignedXml($unsignedXml, $invoiceId);
        }

        if ($signedXml !== null) {
            $files['signed_xml'] = $this->dumpSignedXml($signedXml, $invoiceId);
        }

        if ($qrCode !== null) {
            $files['qr_code'] = $this->dumpQrCode($qrCode, $invoiceId);
        }

        if ($hash !== null) {
            $files['hash'] = $this->dumpHash($hash, $invoiceId);
        }

        return array_filter($files);
    }

    /**
     * Get the debug directory path.
     */
    public function getDebugPath(): string
    {
        return $this->basePath;
    }

    /**
     * List all debug files for an invoice.
     */
    public function listFiles(string $invoiceId): array
    {
        $pattern = $this->basePath.'/'.$this->sanitizeFilename($invoiceId).'*';

        return glob($pattern) ?: [];
    }

    /**
     * Clean up debug files older than specified days.
     */
    public function cleanup(int $days = 7): int
    {
        if (! File::isDirectory($this->basePath)) {
            return 0;
        }

        $count = 0;
        $cutoff = now()->subDays($days)->timestamp;

        foreach (File::files($this->basePath) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $count++;
            }
        }

        return $count;
    }

    /**
     * Dump content to file.
     */
    protected function dump(string $content, string $invoiceId, string $suffix, string $extension): string
    {
        $this->ensureDirectoryExists();

        $filePath = $this->getFilePath($invoiceId, $suffix, $extension);

        File::put($filePath, $content);

        return $filePath;
    }

    /**
     * Get file path for dump.
     */
    protected function getFilePath(string $invoiceId, string $suffix, string $extension): string
    {
        $filename = $this->sanitizeFilename($invoiceId);

        if ($this->options['timestamp_files'] ?? true) {
            $filename .= '_'.date('Ymd_His');
        }

        $filename .= "_{$suffix}.{$extension}";

        return $this->basePath.'/'.$filename;
    }

    /**
     * Sanitize filename.
     */
    protected function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Ensure debug directory exists.
     */
    protected function ensureDirectoryExists(): void
    {
        if (! File::isDirectory($this->basePath)) {
            File::makeDirectory($this->basePath, 0755, true);
        }
    }

    /**
     * Decode QR code for debug output.
     */
    protected function decodeQrForDebug(string $qrCode): string
    {
        $tlv = base64_decode($qrCode);
        $tags = [];
        $offset = 0;
        $tlvLength = strlen($tlv);

        $tagNames = [
            1 => 'Seller Name',
            2 => 'VAT Number',
            3 => 'Timestamp',
            4 => 'Total with VAT',
            5 => 'VAT Amount',
            6 => 'Invoice Hash',
            7 => 'ECDSA Signature',
            8 => 'ECDSA Public Key',
            9 => 'ZATCA Signature',
        ];

        while ($offset < $tlvLength) {
            $tag = ord($tlv[$offset]);
            $offset++;

            $length = ord($tlv[$offset]);
            $offset++;

            // Handle multi-byte length
            if ($length > 127) {
                $numBytes = $length & 0x7F;
                $length = 0;
                for ($i = 0; $i < $numBytes; $i++) {
                    $length = ($length << 8) | ord($tlv[$offset]);
                    $offset++;
                }
            }

            $value = substr($tlv, $offset, $length);
            $offset += $length;

            $tagName = $tagNames[$tag] ?? "Tag {$tag}";

            // For binary data (tags 6-9), show as hex
            if ($tag >= 6) {
                $displayValue = bin2hex($value);
                $tags[] = [
                    'tag' => $tag,
                    'name' => $tagName,
                    'length' => $length,
                    'value_hex' => $displayValue,
                ];
            } else {
                $tags[] = [
                    'tag' => $tag,
                    'name' => $tagName,
                    'length' => $length,
                    'value' => $value,
                ];
            }
        }

        return json_encode([
            'qr_base64_length' => strlen($qrCode),
            'tlv_raw_length' => $tlvLength,
            'tags' => $tags,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
