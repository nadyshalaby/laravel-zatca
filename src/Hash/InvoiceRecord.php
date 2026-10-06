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

    /** The total with VAT, as priced: whole units, a point and two decimals, for the `decimal(15,2)` column. */
    public string $totalAmount;

    /** The VAT, as priced, for the `decimal(15,2)` column. */
    public string $vatAmount;

    public ?string $xml = null;

    public ?string $signedXml = null;

    public ?string $qrCode = null;

    public ?string $referenceId = null;
}
