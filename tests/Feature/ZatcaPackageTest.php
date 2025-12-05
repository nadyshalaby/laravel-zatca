<?php

use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;
use Corecave\Zatca\Enums\PaymentMethod;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Hash\HashChainManager;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Invoice\LineItem;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Qr\TlvEncoder;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Corecave\Zatca\Xml\XmlValidator;

/*
|--------------------------------------------------------------------------
| ZATCA Package Test Suite
|--------------------------------------------------------------------------
|
| This comprehensive test suite covers all ZATCA e-invoicing functionality
| and serves as a reference for building your application.
|
| Test Categories:
| 1. CSR Generator - Certificate Signing Request generation
| 2. Invoice Builder - All invoice types (simplified, standard, credit, debit)
| 3. Line Item - VAT calculations, discounts, categories
| 4. TLV Encoder - QR code encoding/decoding
| 5. QR Generator - Phase 1 & 2 QR codes
| 6. UBL Generator - XML generation
| 7. XML Signer - Digital signatures
| 8. XML Validator - XML validation
| 9. Hash Chain Manager - ICV, PIH, UUID management
| 10. Full Workflow - End-to-end invoice processing
|
*/

// Helper function to create standard seller data
function createSeller(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Company LLC',
        'name_ar' => 'شركة الاختبار',
        'vat_number' => '300000000000003',
        'registration_number' => '1234567890',
        'address' => [
            'street' => 'Main Street',
            'building' => '1234',
            'city' => 'Riyadh',
            'district' => 'Al Olaya',
            'postal_code' => '12345',
            'country' => 'SA',
        ],
    ], $overrides);
}

// Helper function to create standard buyer data
function createBuyer(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Buyer Company',
        'vat_number' => '300000000000004',
        'address' => [
            'street' => 'Other Street',
            'city' => 'Jeddah',
            'country' => 'SA',
        ],
    ], $overrides);
}

// Helper to get initial hash
function getInitialHash(): string
{
    return base64_encode(hash('sha256', '0', true));
}

describe('ZATCA Package', function () {

    /*
    |--------------------------------------------------------------------------
    | CSR Generator Tests
    |--------------------------------------------------------------------------
    */
    describe('CSR Generator', function () {
        it('generates a valid CSR with private key', function () {
            $generator = new CsrGenerator([
                'country' => 'SA',
                'organization' => 'Test Company LLC',
                'organization_unit' => 'Main Branch',
                'common_name' => 'Test Company',
                'vat_number' => '300000000000003',
                'invoice_types' => '1100', // Both B2B and B2C
            ]);

            $result = $generator->generate();

            expect($result)->toHaveKeys(['csr', 'private_key', 'public_key']);
            expect($result['csr'])->toContain('-----BEGIN CERTIFICATE REQUEST-----');
            expect($result['private_key'])->toContain('-----BEGIN PRIVATE KEY-----');
            expect($result['public_key'])->toContain('-----BEGIN PUBLIC KEY-----');
        });

        it('generates CSR for B2C only (simplified invoices)', function () {
            $generator = new CsrGenerator([
                'country' => 'SA',
                'organization' => 'Test Company',
                'organization_unit' => 'Branch 1',
                'common_name' => 'Test',
                'vat_number' => '300000000000003',
                'invoice_types' => '1000', // B2C only
            ]);

            $result = $generator->generate();

            expect($result['csr'])->toContain('-----BEGIN CERTIFICATE REQUEST-----');
        });

        it('generates CSR for B2B only (standard invoices)', function () {
            $generator = new CsrGenerator([
                'country' => 'SA',
                'organization' => 'Test Company',
                'organization_unit' => 'Branch 1',
                'common_name' => 'Test',
                'vat_number' => '300000000000003',
                'invoice_types' => '0100', // B2B only
            ]);

            $result = $generator->generate();

            expect($result['csr'])->toContain('-----BEGIN CERTIFICATE REQUEST-----');
        });

        it('validates VAT number format (must be 15 digits)', function () {
            $generator = new CsrGenerator([
                'country' => 'SA',
                'organization' => 'Test Company',
                'organization_unit' => 'Main Branch',
                'common_name' => 'Test',
                'vat_number' => '123456789', // Invalid: too short
                'invoice_types' => '1100',
            ]);

            expect(fn () => $generator->generate())
                ->toThrow(\Corecave\Zatca\Exceptions\CertificateException::class);
        });

        it('validates VAT number must start and end with 3', function () {
            $generator = new CsrGenerator([
                'country' => 'SA',
                'organization' => 'Test Company',
                'organization_unit' => 'Main Branch',
                'common_name' => 'Test',
                'vat_number' => '100000000000001', // Invalid: doesn't start/end with 3
                'invoice_types' => '1100',
            ]);

            expect(fn () => $generator->generate())
                ->toThrow(\Corecave\Zatca\Exceptions\CertificateException::class);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Invoice Builder Tests - Simplified Invoices (B2C)
    |--------------------------------------------------------------------------
    */
    describe('Invoice Builder - Simplified (B2C)', function () {
        it('creates a simplified invoice with standard VAT', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->addLineItem([
                    'name' => 'Product A',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                    'vat_rate' => 15.00,
                ])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getInvoiceNumber())->toBe('INV-001');
            expect($invoice->isSimplified())->toBeTrue();
            expect($invoice->isStandard())->toBeFalse();
            expect($invoice->getSubtotal())->toBe(200.00);
            expect($invoice->getTotalVat())->toBe(30.00);
            expect($invoice->getTotalWithVat())->toBe(230.00);
            expect($invoice->getType())->toBe(InvoiceType::INVOICE);
            expect($invoice->getSubType())->toBe(InvoiceSubType::SIMPLIFIED);
        });

        it('creates a simplified invoice with multiple line items', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-002')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->addLineItem([
                    'name' => 'Product A',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->addLineItem([
                    'name' => 'Product B',
                    'quantity' => 3,
                    'unit_price' => 50.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            // (2 * 100) + (3 * 50) = 200 + 150 = 350
            expect($invoice->getSubtotal())->toBe(350.00);
            // 350 * 0.15 = 52.50
            expect($invoice->getTotalVat())->toBe(52.50);
            // 350 + 52.50 = 402.50
            expect($invoice->getTotalWithVat())->toBe(402.50);
            expect(count($invoice->getLineItems()))->toBe(2);
        });

        it('creates a simplified invoice without buyer (not required)', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-003')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(3)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getBuyer())->toBeNull();
            expect($invoice->isSimplified())->toBeTrue();
        });

        it('allows optional buyer for simplified invoice', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-004')
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(4)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getBuyer())->not->toBeNull();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Invoice Builder Tests - Standard Invoices (B2B)
    |--------------------------------------------------------------------------
    */
    describe('Invoice Builder - Standard (B2B)', function () {
        it('creates a standard invoice with buyer', function () {
            $invoice = InvoiceBuilder::standard()
                ->setInvoiceNumber('INV-B2B-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->addLineItem([
                    'name' => 'Consulting Service',
                    'quantity' => 5,
                    'unit_price' => 1000.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->isStandard())->toBeTrue();
            expect($invoice->isSimplified())->toBeFalse();
            expect($invoice->getBuyer())->not->toBeNull();
            expect($invoice->getSubtotal())->toBe(5000.00);
            expect($invoice->getTotalVat())->toBe(750.00);
            expect($invoice->getTotalWithVat())->toBe(5750.00);
            expect($invoice->getType())->toBe(InvoiceType::INVOICE);
            expect($invoice->getSubType())->toBe(InvoiceSubType::STANDARD);
        });

        it('requires buyer for standard invoice', function () {
            expect(fn () => InvoiceBuilder::standard()
                ->setInvoiceNumber('INV-B2B-002')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('validates buyer VAT number for B2B', function () {
            $invoice = InvoiceBuilder::standard()
                ->setInvoiceNumber('INV-B2B-003')
                ->setSeller(createSeller())
                ->setBuyer([
                    'name' => 'Buyer Company',
                    'vat_number' => '300000000000004',
                    'address' => ['street' => 'St', 'city' => 'City', 'country' => 'SA'],
                ])
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getBuyer()['vat_number'])->toBe('300000000000004');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Invoice Builder Tests - Credit Notes
    |--------------------------------------------------------------------------
    */
    describe('Invoice Builder - Credit Notes', function () {
        it('creates a simplified credit note', function () {
            $creditNote = InvoiceBuilder::creditNote(simplified: true)
                ->setInvoiceNumber('CN-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->setOriginalInvoice('INV-001')
                ->setReason('Returned goods')
                ->addLineItem([
                    'name' => 'Returned Product',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(5)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($creditNote->isCreditNote())->toBeTrue();
            expect($creditNote->isSimplified())->toBeTrue();
            expect($creditNote->getOriginalInvoiceReference())->toBe('INV-001');
            expect($creditNote->getType())->toBe(InvoiceType::CREDIT_NOTE);
        });

        it('creates a standard credit note', function () {
            $creditNote = InvoiceBuilder::creditNote(simplified: false)
                ->setInvoiceNumber('CN-002')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->setOriginalInvoice('INV-B2B-001')
                ->setReason('Pricing error correction')
                ->addLineItem([
                    'name' => 'Price Adjustment',
                    'quantity' => 1,
                    'unit_price' => 500.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(6)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($creditNote->isCreditNote())->toBeTrue();
            expect($creditNote->isStandard())->toBeTrue();
            expect($creditNote->getOriginalInvoiceReference())->toBe('INV-B2B-001');
        });

        it('requires original invoice reference for credit note', function () {
            expect(fn () => InvoiceBuilder::creditNote(simplified: true)
                ->setInvoiceNumber('CN-003')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(7)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Invoice Builder Tests - Debit Notes
    |--------------------------------------------------------------------------
    */
    describe('Invoice Builder - Debit Notes', function () {
        it('creates a simplified debit note', function () {
            $debitNote = InvoiceBuilder::debitNote(simplified: true)
                ->setInvoiceNumber('DN-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->setOriginalInvoice('INV-001')
                ->setReason('Additional services rendered')
                ->addLineItem([
                    'name' => 'Additional Service',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(8)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($debitNote->isDebitNote())->toBeTrue();
            expect($debitNote->isSimplified())->toBeTrue();
            expect($debitNote->getOriginalInvoiceReference())->toBe('INV-001');
            expect($debitNote->getType())->toBe(InvoiceType::DEBIT_NOTE);
        });

        it('creates a standard debit note', function () {
            $debitNote = InvoiceBuilder::debitNote(simplified: false)
                ->setInvoiceNumber('DN-002')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->setOriginalInvoice('INV-B2B-001')
                ->setReason('Undercharged amount correction')
                ->addLineItem([
                    'name' => 'Price Correction',
                    'quantity' => 1,
                    'unit_price' => 200.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(9)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($debitNote->isDebitNote())->toBeTrue();
            expect($debitNote->isStandard())->toBeTrue();
        });

        it('requires original invoice reference for debit note', function () {
            expect(fn () => InvoiceBuilder::debitNote(simplified: true)
                ->setInvoiceNumber('DN-003')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(10)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Line Item Tests - VAT Categories
    |--------------------------------------------------------------------------
    */
    describe('Line Item - VAT Categories', function () {
        it('calculates standard VAT (15%)', function () {
            $item = LineItem::fromArray([
                'name' => 'Standard Product',
                'quantity' => 2,
                'unit_price' => 100.00,
                'vat_category' => VatCategory::STANDARD,
                'vat_rate' => 15.00,
            ], 1);

            expect($item->getSubtotal())->toBe(200.00);
            expect($item->getVatAmount())->toBe(30.00);
            expect($item->getTotal())->toBe(230.00);
            expect($item->getVatCategory())->toBe(VatCategory::STANDARD);
        });

        it('calculates zero-rated VAT (0%)', function () {
            $item = LineItem::fromArray([
                'name' => 'Zero Rated Product',
                'quantity' => 5,
                'unit_price' => 200.00,
                'vat_category' => VatCategory::ZERO_RATED,
                'vat_rate' => 0.00,
            ], 1);

            expect($item->getSubtotal())->toBe(1000.00);
            expect($item->getVatAmount())->toBe(0.00);
            expect($item->getTotal())->toBe(1000.00);
            expect($item->getVatCategory())->toBe(VatCategory::ZERO_RATED);
        });

        it('calculates exempt VAT', function () {
            $item = LineItem::fromArray([
                'name' => 'Exempt Product',
                'quantity' => 1,
                'unit_price' => 500.00,
                'vat_category' => VatCategory::EXEMPT,
                'vat_rate' => 0.00,
            ], 1);

            expect($item->getSubtotal())->toBe(500.00);
            expect($item->getVatAmount())->toBe(0.00);
            expect($item->getTotal())->toBe(500.00);
            expect($item->getVatCategory())->toBe(VatCategory::EXEMPT);
        });

        it('handles out-of-scope VAT', function () {
            $item = LineItem::fromArray([
                'name' => 'Out of Scope Item',
                'quantity' => 1,
                'unit_price' => 100.00,
                'vat_category' => VatCategory::OUT_OF_SCOPE,
                'vat_rate' => 0.00,
            ], 1);

            expect($item->getVatCategory())->toBe(VatCategory::OUT_OF_SCOPE);
            expect($item->getVatAmount())->toBe(0.00);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Line Item Tests - Discounts
    |--------------------------------------------------------------------------
    */
    describe('Line Item - Discounts', function () {
        it('calculates line item with discount', function () {
            $item = LineItem::fromArray([
                'name' => 'Discounted Product',
                'quantity' => 3,
                'unit_price' => 100.00,
                'vat_category' => VatCategory::STANDARD,
                'vat_rate' => 15.00,
                'discount' => 50.00,
            ], 1);

            // (3 * 100) - 50 = 250
            expect($item->getSubtotal())->toBe(250.00);
            // 250 * 0.15 = 37.50
            expect($item->getVatAmount())->toBe(37.50);
            // 250 + 37.50 = 287.50
            expect($item->getTotal())->toBe(287.50);
        });

        it('calculates invoice total with discounted items', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-DISC-001')
                ->setSeller(createSeller())
                ->addLineItem([
                    'name' => 'Product A',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                    'discount' => 20.00,
                ])
                ->addLineItem([
                    'name' => 'Product B',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                    'vat_category' => VatCategory::STANDARD,
                    'discount' => 10.00,
                ])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            // Item A: (2 * 100) - 20 = 180
            // Item B: (1 * 50) - 10 = 40
            // Subtotal: 180 + 40 = 220
            expect($invoice->getSubtotal())->toBe(220.00);
            // VAT: 220 * 0.15 = 33
            expect($invoice->getTotalVat())->toBe(33.00);
        });

        it('handles zero discount', function () {
            $item = LineItem::fromArray([
                'name' => 'No Discount Product',
                'quantity' => 2,
                'unit_price' => 100.00,
                'vat_category' => VatCategory::STANDARD,
                'discount' => 0.00,
            ], 1);

            expect($item->getSubtotal())->toBe(200.00);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Payment Method Tests
    |--------------------------------------------------------------------------
    */
    describe('Payment Methods', function () {
        it('sets cash payment method', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-PAY-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setPaymentMethod(PaymentMethod::CASH)
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            // getPaymentMethod() returns the string value
            expect($invoice->getPaymentMethod())->toBe('10');
            // getPaymentMethodEnum() returns the enum
            expect($invoice->getPaymentMethodEnum())->toBe(PaymentMethod::CASH);
        });

        it('sets bank transfer payment method', function () {
            $invoice = InvoiceBuilder::standard()
                ->setInvoiceNumber('INV-PAY-002')
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 1000])
                ->setPaymentMethod(PaymentMethod::BANK_TRANSFER)
                ->setPaymentTerms('Net 30')
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getPaymentMethod())->toBe('42');
            expect($invoice->getPaymentMethodEnum())->toBe(PaymentMethod::BANK_TRANSFER);
            expect($invoice->getPaymentTerms())->toBe('Net 30');
        });

        it('sets bank card payment method', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-PAY-003')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setPaymentMethod(PaymentMethod::BANK_CARD)
                ->setIcv(3)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getPaymentMethod())->toBe('48');
            expect($invoice->getPaymentMethodEnum())->toBe(PaymentMethod::BANK_CARD);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Currency Tests
    |--------------------------------------------------------------------------
    */
    describe('Multi-Currency Support', function () {
        it('defaults to SAR currency', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-CURR-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getCurrency())->toBe('SAR');
        });

        it('supports USD currency', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-CURR-002')
                ->setSeller(createSeller())
                ->setCurrency('USD')
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getCurrency())->toBe('USD');
        });

        it('supports EUR currency', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-CURR-003')
                ->setSeller(createSeller())
                ->setCurrency('EUR')
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(3)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getCurrency())->toBe('EUR');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | TLV Encoder Tests
    |--------------------------------------------------------------------------
    */
    describe('TLV Encoder', function () {
        it('encodes and decodes TLV data', function () {
            $encoder = new TlvEncoder;

            $tags = [
                1 => 'Test Seller',
                2 => '300000000000003',
                3 => '2024-01-15T10:30:00Z',
                4 => '115.00',
                5 => '15.00',
            ];

            $encoded = $encoder->encode($tags);
            $decoded = $encoder->decode($encoded);

            expect($decoded)->toBe($tags);
        });

        it('encodes Arabic text correctly', function () {
            $encoder = new TlvEncoder;

            $tags = [
                1 => 'شركة الاختبار',
                2 => '300000000000003',
            ];

            $encoded = $encoder->encode($tags);
            $decoded = $encoder->decode($encoded);

            expect($decoded[1])->toBe('شركة الاختبار');
        });

        it('handles all 9 Phase 2 QR tags', function () {
            $encoder = new TlvEncoder;

            $tags = [
                1 => 'Seller Name',
                2 => '300000000000003',
                3 => '2024-01-15T10:30:00Z',
                4 => '115.00',
                5 => '15.00',
                6 => 'invoice_hash_here',
                7 => 'signature_here',
                8 => 'public_key_here',
                9 => 'certificate_signature',
            ];

            $encoded = $encoder->encode($tags);
            $decoded = $encoder->decode($encoded);

            expect($decoded)->toHaveCount(9);
            expect($decoded[6])->toBe('invoice_hash_here');
            expect($decoded[7])->toBe('signature_here');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | QR Generator Tests
    |--------------------------------------------------------------------------
    */
    describe('QR Generator', function () {
        it('generates phase 1 QR code (5 tags)', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-QR-001')
                ->setSeller([
                    'name_ar' => 'شركة الاختبار',
                    'vat_number' => '300000000000003',
                    'address' => ['street' => 'St', 'city' => 'Riyadh', 'country' => 'SA'],
                ])
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $qrGenerator = new QrGenerator;
            $qrCode = $qrGenerator->generatePhase1($invoice);

            expect($qrCode)->not->toBeEmpty();

            // Decode and verify basic tags
            $tags = $qrGenerator->decode($qrCode);
            expect($tags[1])->toBe('شركة الاختبار'); // Seller name
            expect($tags[2])->toBe('300000000000003'); // VAT number
            expect($tags)->toHaveKey(3); // Timestamp
            expect($tags)->toHaveKey(4); // Total with VAT
            expect($tags)->toHaveKey(5); // VAT amount
        });

        it('decodes QR code back to original values', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-QR-002')
                ->setSeller([
                    'name_ar' => 'البائع',
                    'vat_number' => '300000000000003',
                    'address' => ['street' => 'St', 'city' => 'Riyadh', 'country' => 'SA'],
                ])
                ->addLineItem([
                    'name' => 'Test',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $qrGenerator = new QrGenerator;
            $qrCode = $qrGenerator->generatePhase1($invoice);
            $tags = $qrGenerator->decode($qrCode);

            expect($tags[4])->toBe('230.00'); // Total with VAT: 200 + 30
            expect($tags[5])->toBe('30.00');  // VAT amount: 200 * 0.15
        });
    });

    /*
    |--------------------------------------------------------------------------
    | UBL Generator Tests
    |--------------------------------------------------------------------------
    */
    describe('UBL Generator', function () {
        it('generates valid UBL XML for simplified invoice', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-XML-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->addLineItem([
                    'name' => 'Test Product',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>');
            expect($xml)->toContain('<Invoice');
            expect($xml)->toContain('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
            expect($xml)->toContain('<cbc:ID>INV-XML-001</cbc:ID>');
            expect($xml)->toContain('<cbc:UUID>'.$invoice->getUuid().'</cbc:UUID>');
            expect($xml)->toContain('<cbc:InvoiceTypeCode');
        });

        it('generates XML with correct invoice type code', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-XML-002')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            // Invoice type code 388 for invoices
            expect($xml)->toContain('388');
        });

        it('generates XML for credit note with type code 381', function () {
            $creditNote = InvoiceBuilder::creditNote(simplified: true)
                ->setInvoiceNumber('CN-XML-001')
                ->setSeller(createSeller())
                ->setOriginalInvoice('INV-001')
                ->setReason('Returned')
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(3)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($creditNote);

            // Credit note type code 381
            expect($xml)->toContain('381');
        });

        it('includes seller information in XML', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-XML-003')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(4)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            expect($xml)->toContain('300000000000003'); // VAT number
            expect($xml)->toContain('AccountingSupplierParty');
        });

        it('includes buyer information in standard invoice XML', function () {
            $invoice = InvoiceBuilder::standard()
                ->setInvoiceNumber('INV-XML-004')
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(5)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            expect($xml)->toContain('AccountingCustomerParty');
            expect($xml)->toContain('300000000000004'); // Buyer VAT number
        });

        it('includes line items in XML', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-XML-005')
                ->setSeller(createSeller())
                ->addLineItem([
                    'name' => 'Product A',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->addLineItem([
                    'name' => 'Product B',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setIcv(6)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            expect($xml)->toContain('InvoiceLine');
            expect($xml)->toContain('Product A');
            expect($xml)->toContain('Product B');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | XML Validator Tests
    |--------------------------------------------------------------------------
    */
    describe('XML Validator', function () {
        it('validates well-formed XML', function () {
            $xml = '<?xml version="1.0"?><root><child>test</child></root>';
            $validator = new XmlValidator;

            expect($validator->isWellFormed($xml))->toBeTrue();
        });

        it('rejects malformed XML', function () {
            $xml = '<?xml version="1.0"?><root><unclosed>';
            $validator = new XmlValidator;

            expect($validator->isWellFormed($xml))->toBeFalse();
        });

        it('validates XML with namespaces', function () {
            $xml = '<?xml version="1.0"?><root xmlns="http://example.com"><child>test</child></root>';
            $validator = new XmlValidator;

            expect($validator->isWellFormed($xml))->toBeTrue();
        });

        it('validates UBL invoice XML structure', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-VAL-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            $validator = new XmlValidator;
            expect($validator->isWellFormed($xml))->toBeTrue();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | XML Signer Tests
    |--------------------------------------------------------------------------
    */
    describe('XML Signer', function () {
        it('generates invoice hash', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-SIGN-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            $signer = new XmlSigner;
            $hash = $signer->generateInvoiceHash($xml);

            expect($hash)->not->toBeEmpty();
            // Hash should be base64 encoded
            expect(base64_decode($hash, true))->not->toBeFalse();
        });

        it('generates consistent hash for same invoice', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-SIGN-002')
                ->setIssueDate('2024-01-15')
                ->setUuid('550e8400-e29b-41d4-a716-446655440000')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(2)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            $signer = new XmlSigner;
            $hash1 = $signer->generateInvoiceHash($xml);
            $hash2 = $signer->generateInvoiceHash($xml);

            expect($hash1)->toBe($hash2);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Hash Chain Manager Tests
    |--------------------------------------------------------------------------
    */
    describe('Hash Chain Manager', function () {
        it('returns initial hash for first invoice', function () {
            $manager = new HashChainManager;
            $initialHash = $manager->getInitialHash();

            // SHA-256 of "0" in base64
            $expected = base64_encode(hash('sha256', '0', true));

            expect($initialHash)->toBe($expected);
        });

        it('generates unique UUIDs', function () {
            $manager = new HashChainManager;

            $uuid1 = $manager->generateUuid();
            $uuid2 = $manager->generateUuid();
            $uuid3 = $manager->generateUuid();

            expect($uuid1)->not->toBe($uuid2);
            expect($uuid2)->not->toBe($uuid3);
            expect($uuid1)->toMatch('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/');
        });

        it('validates UUID format', function () {
            $manager = new HashChainManager;
            $uuid = $manager->generateUuid();

            // UUID v4 format
            expect(strlen($uuid))->toBe(36);
            expect(substr_count($uuid, '-'))->toBe(4);
        });

        it('provides hash for invoice chain continuity', function () {
            $manager = new HashChainManager;

            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-HASH-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash($manager->getInitialHash())
                ->build();

            $generator = new UblGenerator;
            $xml = $generator->generate($invoice);

            // Use XmlSigner to generate hash
            $signer = new XmlSigner;
            $hash = $signer->generateInvoiceHash($xml);

            expect($hash)->not->toBeEmpty();
            expect(base64_decode($hash, true))->not->toBeFalse();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Invoice Validation Tests
    |--------------------------------------------------------------------------
    */
    describe('Invoice Validation', function () {
        it('requires invoice number', function () {
            expect(fn () => InvoiceBuilder::simplified()
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('requires seller information or uses config', function () {
            // Note: When no seller is explicitly set, InvoiceBuilder uses seller config from zatca.seller
            // This test verifies that empty seller array throws an exception
            expect(fn () => InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setSeller([]) // Explicitly set empty seller
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('requires seller VAT number', function () {
            expect(fn () => InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setSeller(['name' => 'Test', 'address' => ['city' => 'Riyadh', 'country' => 'SA']])
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('requires at least one line item', function () {
            expect(fn () => InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setSeller(createSeller())
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('validates line item has name', function () {
            expect(fn () => InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setSeller(createSeller())
                ->addLineItem(['quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('validates line item quantity is positive', function () {
            expect(fn () => InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 0, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });

        it('validates line item unit price is not negative', function () {
            expect(fn () => InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => -100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build()
            )->toThrow(\Corecave\Zatca\Exceptions\InvoiceException::class);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Full Workflow Tests
    |--------------------------------------------------------------------------
    */
    describe('Full Invoice Workflow', function () {
        it('completes simplified invoice workflow: build -> XML -> hash -> QR', function () {
            // Step 1: Build the invoice
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-FLOW-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->addLineItem([
                    'name' => 'Product A',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'vat_category' => VatCategory::STANDARD,
                    'vat_rate' => 15.00,
                ])
                ->setPaymentMethod(PaymentMethod::CASH)
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getInvoiceNumber())->toBe('INV-FLOW-001');

            // Step 2: Generate XML
            $xmlGenerator = new UblGenerator;
            $xml = $xmlGenerator->generate($invoice);

            expect($xml)->toContain('INV-FLOW-001');

            // Step 3: Validate XML
            $validator = new XmlValidator;
            expect($validator->isWellFormed($xml))->toBeTrue();

            // Step 4: Generate hash
            $signer = new XmlSigner;
            $hash = $signer->generateInvoiceHash($xml);

            expect($hash)->not->toBeEmpty();

            // Step 5: Generate QR code
            $qrGenerator = new QrGenerator;
            $qrCode = $qrGenerator->generatePhase1($invoice);

            expect($qrCode)->not->toBeEmpty();

            // Verify QR contains correct data
            $tags = $qrGenerator->decode($qrCode);
            expect($tags[2])->toBe('300000000000003');
            expect($tags[4])->toBe('230.00'); // 200 + 30 VAT
        });

        it('completes standard invoice workflow with buyer', function () {
            // Build standard B2B invoice
            $invoice = InvoiceBuilder::standard()
                ->setInvoiceNumber('INV-B2B-FLOW-001')
                ->setIssueDate(now())
                ->setSeller(createSeller())
                ->setBuyer(createBuyer())
                ->addLineItem([
                    'name' => 'Consulting Services',
                    'quantity' => 10,
                    'unit_price' => 500.00,
                    'vat_category' => VatCategory::STANDARD,
                ])
                ->setPaymentMethod(PaymentMethod::BANK_TRANSFER)
                ->setPaymentTerms('Net 30 days')
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            // Generate and validate XML
            $xmlGenerator = new UblGenerator;
            $xml = $xmlGenerator->generate($invoice);

            $validator = new XmlValidator;
            expect($validator->isWellFormed($xml))->toBeTrue();

            // Verify buyer in XML
            expect($xml)->toContain('AccountingCustomerParty');
            expect($xml)->toContain('300000000000004');

            // Verify totals
            expect($invoice->getSubtotal())->toBe(5000.00);
            expect($invoice->getTotalVat())->toBe(750.00);
            expect($invoice->getTotalWithVat())->toBe(5750.00);
        });

        it('handles invoice chain with incrementing ICV', function () {
            $hashManager = new HashChainManager;
            $previousHash = $hashManager->getInitialHash();

            // First invoice
            $invoice1 = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-CHAIN-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Product', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash($previousHash)
                ->build();

            expect($invoice1->getIcv())->toBe(1);
            expect($invoice1->getPreviousInvoiceHash())->toBe($previousHash);

            // Generate hash for first invoice
            $xmlGenerator = new UblGenerator;
            $signer = new XmlSigner;
            $xml1 = $xmlGenerator->generate($invoice1);
            $hash1 = $signer->generateInvoiceHash($xml1);

            // Second invoice (chain continuation)
            $invoice2 = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-CHAIN-002')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Product', 'quantity' => 1, 'unit_price' => 200])
                ->setIcv(2)
                ->setPreviousInvoiceHash($hash1)
                ->build();

            expect($invoice2->getIcv())->toBe(2);
            expect($invoice2->getPreviousInvoiceHash())->toBe($hash1);

            // Third invoice
            $xml2 = $xmlGenerator->generate($invoice2);
            $hash2 = $signer->generateInvoiceHash($xml2);

            $invoice3 = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-CHAIN-003')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Product', 'quantity' => 1, 'unit_price' => 300])
                ->setIcv(3)
                ->setPreviousInvoiceHash($hash2)
                ->build();

            expect($invoice3->getIcv())->toBe(3);
            expect($invoice3->getPreviousInvoiceHash())->toBe($hash2);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Invoice Notes Tests
    |--------------------------------------------------------------------------
    */
    describe('Invoice Notes', function () {
        it('adds notes to invoice', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-NOTES-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->addNote('This is a test invoice')
                ->addNote('Payment due within 30 days')
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getNotes())->toHaveCount(2);
            expect($invoice->getNotes())->toContain('This is a test invoice');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Supply Date Tests
    |--------------------------------------------------------------------------
    */
    describe('Supply Date', function () {
        it('sets supply date different from issue date', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-SUPPLY-001')
                ->setIssueDate('2024-01-15')
                ->setSupplyDate('2024-01-10')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getIssueDate()->format('Y-m-d'))->toBe('2024-01-15');
            expect($invoice->getSupplyDate()->format('Y-m-d'))->toBe('2024-01-10');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | UUID Tests
    |--------------------------------------------------------------------------
    */
    describe('Invoice UUID', function () {
        it('auto-generates UUID when not set', function () {
            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-UUID-001')
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getUuid())->not->toBeEmpty();
            expect($invoice->getUuid())->toMatch('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/');
        });

        it('uses provided UUID when set', function () {
            $customUuid = '550e8400-e29b-41d4-a716-446655440000';

            $invoice = InvoiceBuilder::simplified()
                ->setInvoiceNumber('INV-UUID-002')
                ->setUuid($customUuid)
                ->setSeller(createSeller())
                ->addLineItem(['name' => 'Test', 'quantity' => 1, 'unit_price' => 100])
                ->setIcv(1)
                ->setPreviousInvoiceHash(getInitialHash())
                ->build();

            expect($invoice->getUuid())->toBe($customUuid);
        });
    });
});
