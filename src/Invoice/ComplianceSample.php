<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Enums\VatCategory;

/**
 * What the invoice builder fills into a sample invoice for the compliance checks.
 */
class ComplianceSample
{
    public const INVOICE_NUMBER = 'SME00001';

    /**
     * One line priced as Syanat prices: 1 × 100.00, no discount, 15% VAT rounded once from the net.
     */
    public const LINE_ITEM = [
        'name' => 'Sample Item',
        'quantity' => '1',
        'unit_price' => '100.00',
        'line_amount' => '100.00',
        'net' => '100.00',
        'vat' => '15.00',
        'gross' => '115.00',
        'vat_category' => VatCategory::STANDARD,
        'vat_rate' => '15.00',
    ];

    /**
     * The sample's one VAT group, its tax rounded once from the group's taxable amount.
     */
    public const VAT_GROUPS = [
        ['category' => VatCategory::STANDARD, 'rate' => '15.00', 'taxable' => '100.00', 'tax' => '15.00'],
    ];

    /**
     * The sample's totals: the line nets, the groups' tax and the two added.
     */
    public const TOTALS = ['net' => '100.00', 'vat' => '15.00', 'gross' => '115.00'];

    /**
     * A buyer with the full address standard invoices require.
     */
    public const STANDARD_BUYER = [
        'name' => 'Sample Buyer',
        'vat_number' => '310122393500003',
        'registration_number' => '3101223935',
        'address' => [
            'street' => 'Sample Street',
            'building' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'district' => 'Sample District',
            'country' => 'SA',
        ],
    ];

    /**
     * The original invoice and the reason credit and debit notes require.
     */
    public const ORIGINAL_INVOICE = 'INV-2024-001';

    public const REASON = 'Correction of invoice';
}
