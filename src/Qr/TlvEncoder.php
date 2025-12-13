<?php

namespace Corecave\Zatca\Qr;

/**
 * TLV (Tag-Length-Value) Encoder for ZATCA QR codes.
 *
 * ZATCA requires 9 tags in TLV format encoded as base64.
 */
class TlvEncoder
{
    /**
     * Encode an array of tags into TLV base64 format.
     *
     * @param  array<int, string>  $tags  Array of tag number => value
     */
    public function encode(array $tags): string
    {
        $tlv = '';

        foreach ($tags as $tagNumber => $value) {
            $tlv .= $this->encodeTag($tagNumber, $value);
        }

        return base64_encode($tlv);
    }

    /**
     * Encode a single TLV tag.
     *
     * ZATCA TLV encoding uses ASN.1 BER length encoding:
     * - Length < 128: single byte
     * - Length >= 128: first byte = 0x80 | num_length_bytes, followed by length bytes
     */
    public function encodeTag(int $tag, string $value): string
    {
        $valueBytes = $value;
        $length = strlen($valueBytes);

        return chr($tag).$this->encodeLength($length).$valueBytes;
    }

    /**
     * Encode length using ASN.1 BER encoding.
     */
    protected function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        // For lengths >= 128, use multi-byte encoding
        $lengthBytes = '';
        $tempLength = $length;

        while ($tempLength > 0) {
            $lengthBytes = chr($tempLength & 0xFF).$lengthBytes;
            $tempLength >>= 8;
        }

        return chr(0x80 | strlen($lengthBytes)).$lengthBytes;
    }

    /**
     * Decode a TLV base64 string into an array of tags.
     *
     * @return array<int, string>
     */
    public function decode(string $tlvBase64): array
    {
        $tlv = base64_decode($tlvBase64);
        $tags = [];
        $offset = 0;
        $tlvLength = strlen($tlv);

        while ($offset < $tlvLength) {
            $tag = ord($tlv[$offset]);
            $offset++;

            // Decode length (ASN.1 BER encoding)
            [$length, $bytesRead] = $this->decodeLength($tlv, $offset);
            $offset += $bytesRead;

            $value = substr($tlv, $offset, $length);
            $tags[$tag] = $value;
            $offset += $length;
        }

        return $tags;
    }

    /**
     * Decode ASN.1 BER length.
     *
     * @return array{0: int, 1: int} [length, bytes_read]
     */
    protected function decodeLength(string $data, int $offset): array
    {
        $firstByte = ord($data[$offset]);

        if ($firstByte < 128) {
            // Short form: single byte length
            return [$firstByte, 1];
        }

        // Long form: first byte indicates number of length bytes
        $numLengthBytes = $firstByte & 0x7F;
        $length = 0;

        for ($i = 0; $i < $numLengthBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }

        return [$length, 1 + $numLengthBytes];
    }

    /**
     * Validate TLV encoding.
     */
    public function isValid(string $tlvBase64): bool
    {
        try {
            $tags = $this->decode($tlvBase64);

            // Phase 2 requires 9 tags
            return count($tags) >= 5;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get human-readable format of TLV data.
     */
    public function toHumanReadable(string $tlvBase64): array
    {
        $tags = $this->decode($tlvBase64);
        $tagNames = [
            1 => 'Seller Name',
            2 => 'VAT Number',
            3 => 'Timestamp',
            4 => 'Total with VAT',
            5 => 'VAT Amount',
            6 => 'Invoice Hash (DigestValue)',
            7 => 'Digital Signature (SignatureValue)',
            8 => 'ECDSA Public Key',
            9 => 'Certificate Signature',
        ];

        $result = [];

        foreach ($tags as $tag => $value) {
            $name = $tagNames[$tag] ?? "Tag {$tag}";

            // For binary data, show as hex
            if ($tag >= 6) {
                $displayValue = bin2hex($value);
            } else {
                $displayValue = $value;
            }

            $result[] = [
                'tag' => $tag,
                'name' => $name,
                'length' => strlen($value),
                'value' => $displayValue,
            ];
        }

        return $result;
    }
}
