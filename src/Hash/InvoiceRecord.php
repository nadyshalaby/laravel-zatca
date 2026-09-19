<?php

namespace Corecave\Zatca\Hash;

/**
 * What the hash chain keeps for one invoice: its place in the chain, its kind, its amounts and its documents.
 *
 * Every property without a default must be filled in before the invoice is recorded.
 */
class InvoiceRecord
{
    public string $uuid;

    public int $icv;

    public string $invoiceNumber;

    public string $hash;

    public string $previousHash;

    public string $type;

    public string $subType;

    public float $totalAmount;

    public float $vatAmount;

    public ?string $xml = null;

    public ?string $signedXml = null;

    public ?string $qrCode = null;

    public ?string $referenceId = null;
}
