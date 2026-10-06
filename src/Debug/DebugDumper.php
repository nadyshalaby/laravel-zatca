<?php

namespace Corecave\Zatca\Debug;

use Corecave\Zatca\Invoice\InvoiceDocuments;
use Illuminate\Support\Facades\File;

/**
 * Debug dumper for ZATCA invoice processing.
 *
 * Dumps generated XML, QR codes, and hashes to files for debugging.
 */
class DebugDumper
{
    /**
     * What dump() writes after an invoice's id, one ending per kind of file.
     */
    private const FILE_ENDINGS = ['unsigned.xml', 'signed.xml', 'qr.txt', 'qr_decoded.json', 'qr_decode_error.txt', 'hash.txt'];

    /**
     * The names of the nine QR code tags, as the decoded QR file shows them.
     */
    private const QR_TAG_NAMES = [
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

        return $this->dump($xml, $invoiceId, 'unsigned.xml');
    }

    /**
     * Dump signed XML.
     */
    public function dumpSignedXml(string $xml, string $invoiceId): ?string
    {
        if (! $this->enabled || ! ($this->options['dump_signed_xml'] ?? true)) {
            return null;
        }

        return $this->dump($xml, $invoiceId, 'signed.xml');
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
        $this->dump($qrCode, $invoiceId, 'qr.txt');

        // Also dump human-readable QR info if possible
        try {
            $decoded = $this->decodeQrForDebug($qrCode);
            $this->dump($decoded, $invoiceId, 'qr_decoded.json');
        } catch (\Exception $e) {
            $this->dump($e->getMessage(), $invoiceId, 'qr_decode_error.txt');
        }

        return $this->getFilePath($invoiceId, 'qr.txt');
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

        return $this->dump($content, $invoiceId, 'hash.txt');
    }

    /**
     * Dump all invoice artifacts at once.
     */
    public function dumpAll(string $invoiceId, InvoiceDocuments $documents = new InvoiceDocuments, ?string $hash = null): array
    {
        $files = [];

        if ($documents->unsignedXml !== null) {
            $files['unsigned_xml'] = $this->dumpUnsignedXml($documents->unsignedXml, $invoiceId);
        }

        if ($documents->signedXml !== null) {
            $files['signed_xml'] = $this->dumpSignedXml($documents->signedXml, $invoiceId);
        }

        if ($documents->qrCode !== null) {
            $files['qr_code'] = $this->dumpQrCode($documents->qrCode, $invoiceId);
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
     * List all debug files for an invoice: its id, the stamp when files are stamped, then one of the endings dump() writes.
     *
     * @return list<string>
     */
    public function listFiles(string $invoiceId): array
    {
        $id = $this->sanitizeFilename($invoiceId);
        $endings = implode('|', array_map(fn (string $ending): string => preg_quote($ending, '/'), self::FILE_ENDINGS));
        $ownName = '/^'.preg_quote($id, '/').'(_\d{8}_\d{6})?_('.$endings.')$/';

        return array_values(array_filter(
            glob($this->basePath.'/'.$id.'_*') ?: [],
            fn (string $path): bool => preg_match($ownName, basename($path)) === 1,
        ));
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
     *
     * @param  string  $fileEnding  What follows the invoice ID in the file name, such as "signed.xml"
     */
    protected function dump(string $content, string $invoiceId, string $fileEnding): string
    {
        $this->ensureDirectoryExists();

        $filePath = $this->getFilePath($invoiceId, $fileEnding);

        File::put($filePath, $content);

        return $filePath;
    }

    /**
     * Get file path for dump.
     */
    protected function getFilePath(string $invoiceId, string $fileEnding): string
    {
        $filename = $this->sanitizeFilename($invoiceId);

        if ($this->options['timestamp_files'] ?? true) {
            $filename .= '_'.date('Ymd_His');
        }

        $filename .= "_{$fileEnding}";

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

        while ($offset < $tlvLength) {
            [$tag, $length, $offset] = $this->readTlvHeader($tlv, $offset);

            $value = substr($tlv, $offset, $length);
            $offset += $length;

            $tags[] = $this->describeTlvTag($tag, $length, $value);
        }

        return json_encode([
            'qr_base64_length' => strlen($qrCode),
            'tlv_raw_length' => $tlvLength,
            'tags' => $tags,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Read the tag number and the value length at the offset, a length above 127 spread over the bytes it counts.
     *
     * @return array{0: int, 1: int, 2: int} the tag, the value length and the offset of the value
     */
    protected function readTlvHeader(string $tlv, int $offset): array
    {
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

        return [$tag, $length, $offset];
    }

    /**
     * One QR code tag as the decoded file shows it, the binary tags 6 to 9 as hex.
     *
     * @return array<string, int|string>
     */
    protected function describeTlvTag(int $tag, int $length, string $value): array
    {
        $tagName = self::QR_TAG_NAMES[$tag] ?? "Tag {$tag}";

        // For binary data (tags 6-9), show as hex
        if ($tag >= 6) {
            return [
                'tag' => $tag,
                'name' => $tagName,
                'length' => $length,
                'value_hex' => bin2hex($value),
            ];
        }

        return [
            'tag' => $tag,
            'name' => $tagName,
            'length' => $length,
            'value' => $value,
        ];
    }
}
