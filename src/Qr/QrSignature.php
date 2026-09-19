<?php

namespace Corecave\Zatca\Qr;

/**
 * What the QR code carries from the signature: the XML signature value (tag 7), the signing public key as
 * SPKI DER bytes (tag 8) and the certificate's own signature bytes (tag 9).
 */
class QrSignature
{
    public function __construct(
        public readonly string $signatureValue,
        public readonly string $publicKey,
        public readonly string $certificateSignature,
    ) {}
}
