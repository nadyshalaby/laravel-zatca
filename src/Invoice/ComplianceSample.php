<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Enums\VatCategory;

/**
 * What the invoice builder fills into a sample invoice for the compliance checks.
 */
class ComplianceSample
{
    public const INVOICE_NUMBER = 'SME00001';

    public const LINE_ITEM = [
        'name' => 'Sample Item',
        'quantity' => 1,
        'unit_price' => 100.00,
        'vat_category' => VatCategory::STANDARD,
        'vat_rate' => 15.00,
    ];

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
