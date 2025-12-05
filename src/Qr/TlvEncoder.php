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
     */
    public function encodeTag(int $tag, string $value): string
    {
        $valueBytes = $value;
        $length = strlen($valueBytes);

        return chr($tag).chr($length).$valueBytes;
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

        while ($offset < strlen($tlv)) {
            $tag = ord($tlv[$offset]);
            $length = ord($tlv[$offset + 1]);
            $value = substr($tlv, $offset + 2, $length);

            $tags[$tag] = $value;
            $offset += 2 + $length;
        }

        return $tags;
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
            6 => 'Invoice Hash',
            7 => 'ECDSA Signature',
            8 => 'ECDSA Public Key',
            9 => 'ZATCA Signature',
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
