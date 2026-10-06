<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;

/**
 * Everything an invoice line is made of, every figure as the caller priced it.
 *
 * Every property without a default must be filled in before a line is made from it. The line writes these
 * figures as they are and works none of them out: the line amount is the quantity times the unit price, the
 * net (BT-131) the line amount less the discount, the VAT (KSA-11) rounded once from the net, and the gross
 * (KSA-12) the net plus that VAT.
 */
class LineItemData
{
    public int $id;

    public string $name;

    /** The quantity (BT-129), whole units with up to six decimals. */
    public string $quantity;

    public Amount $unitPrice;

    public Amount $lineAmount;

    public Amount $discount;

    public Amount $net;

    public Amount $vat;

    public Amount $gross;

    public VatCategory $vatCategory = VatCategory::STANDARD;

    /** The VAT rate (BT-152), a percentage with two decimals. */
    public string $vatRate;

    public string $unitCode = 'PCE';

    public ?VatExemptionReason $vatExemptionReason = null;

    /** @var array<string, mixed> */
    public array $identifiers = [];
}
