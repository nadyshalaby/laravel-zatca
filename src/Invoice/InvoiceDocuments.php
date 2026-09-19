<?php

namespace Corecave\Zatca\Invoice;

/**
 * The documents made for one invoice: its XML before and after signing and its QR code, each only once made.
 */
class InvoiceDocuments
{
    public function __construct(
        public readonly ?string $unsignedXml = null,
        public readonly ?string $signedXml = null,
        public readonly ?string $qrCode = null,
    ) {}
}
