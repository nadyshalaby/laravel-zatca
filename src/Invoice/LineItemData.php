<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;

/**
 * Everything an invoice line is made of.
 *
 * Every property without a default must be filled in before a line is made from it; a missing VAT rate
 * falls back to the VAT category's default rate.
 */
class LineItemData
{
    public int $id;

    public string $name;

    public float $quantity;

    public float $unitPrice;

    public VatCategory $vatCategory = VatCategory::STANDARD;

    public ?float $vatRate = null;

    public string $unitCode = 'PCE';

    public float $discount = 0.0;

    public ?VatExemptionReason $vatExemptionReason = null;

    /** @var array<string, mixed> */
    public array $identifiers = [];
}
