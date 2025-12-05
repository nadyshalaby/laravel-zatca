# Laravel ZATCA

A comprehensive Laravel package for integrating with Saudi Arabia's ZATCA (Zakat, Tax and Customs Authority) e-invoicing system (FATOORA platform).

This package supports Phase 2 requirements including:
- CSR generation and certificate management
- Invoice XML generation (UBL 2.1 compliant)
- Digital signing with ECDSA (secp256k1)
- QR code generation (9 TLV tags)
- Invoice reporting (B2C simplified invoices)
- Invoice clearance (B2B standard invoices)
- Credit and debit note handling
- Sandbox and production environments

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- OpenSSL extension
- GMP extension (recommended for better performance)

## Installation

```bash
composer require corecave/laravel-zatca
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=zatca-config
```

Run the migrations:

```bash
php artisan migrate
```

## Configuration

Add these environment variables to your `.env` file:

```env
# Environment: sandbox, simulation, or production
ZATCA_ENVIRONMENT=sandbox

# Seller Information
ZATCA_SELLER_NAME="Your Company Name"
ZATCA_SELLER_NAME_AR="اسم شركتك"
ZATCA_VAT_NUMBER=300000000000003
ZATCA_REGISTRATION_NUMBER=1234567890

# Seller Address
ZATCA_SELLER_STREET="Main Street"
ZATCA_SELLER_BUILDING="1234"
ZATCA_SELLER_CITY="Riyadh"
ZATCA_SELLER_DISTRICT="Al Olaya"
ZATCA_SELLER_POSTAL_CODE="12345"

# CSR Configuration
ZATCA_CSR_ORGANIZATION="Your Company Name"
ZATCA_CSR_ORGANIZATION_UNIT="Main Branch"
ZATCA_CSR_COMMON_NAME="Your Company Name"
ZATCA_INVOICE_TYPES=1100
```

## Onboarding Process

### Step 1: Generate CSR

```bash
php artisan zatca:generate-csr --save
```

### Step 2: Get Compliance CSID

1. Log in to the [FATOORA portal](https://fatoora.zatca.gov.sa/)
2. Generate an OTP for your EGS unit
3. Run the compliance command:

```bash
php artisan zatca:compliance --otp=123456
```

### Step 3: Get Production CSID

After passing compliance checks:

```bash
php artisan zatca:production-csid
```

## Usage

### Creating a Simplified Invoice (B2C)

```php
use Corecave\Zatca\Facades\Zatca;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Enums\VatCategory;

// Build the invoice
$invoice = InvoiceBuilder::simplified()
    ->setInvoiceNumber('INV-2024-001')
    ->setIssueDate(now())
    ->addLineItem([
        'name' => 'Product Name',
        'quantity' => 2,
        'unit_price' => 100.00,
        'vat_category' => VatCategory::STANDARD,
    ])
    ->addLineItem([
        'name' => 'Service',
        'quantity' => 1,
        'unit_price' => 250.00,
        'vat_category' => VatCategory::STANDARD,
    ])
    ->build();

// Report to ZATCA
$result = Zatca::report($invoice);

if ($result->isSuccess()) {
    $qrCode = $result->getQrCode();
    $signedXml = $result->getSignedXml();

    // Store or display the QR code
}
```

### Creating a Standard Invoice (B2B)

```php
use Corecave\Zatca\Facades\Zatca;
use Corecave\Zatca\Invoice\InvoiceBuilder;

$invoice = InvoiceBuilder::standard()
    ->setInvoiceNumber('INV-2024-002')
    ->setIssueDate(now())
    ->setBuyer([
        'name' => 'Buyer Company LLC',
        'vat_number' => '300000000000003',
        'registration_number' => '1234567890',
        'address' => [
            'street' => 'King Fahd Road',
            'building' => '1234',
            'city' => 'Riyadh',
            'district' => 'Al Olaya',
            'postal_code' => '12345',
            'country' => 'SA',
        ],
    ])
    ->addLineItem([
        'name' => 'Consulting Services',
        'quantity' => 10,
        'unit_price' => 1000.00,
        'vat_category' => VatCategory::STANDARD,
    ])
    ->build();

// Clear with ZATCA (must be done BEFORE issuing to customer)
$result = Zatca::clear($invoice);

if ($result->isSuccess()) {
    // ZATCA adds cryptographic stamp and QR code
    $clearedXml = $result->getClearedXml();
    $qrCode = $result->getQrCode();
}
```

### Creating Credit/Debit Notes

```php
use Corecave\Zatca\Invoice\InvoiceBuilder;

// Credit note for a standard invoice
$creditNote = InvoiceBuilder::creditNote(simplified: false)
    ->setInvoiceNumber('CN-2024-001')
    ->setOriginalInvoice('INV-2024-002') // Reference original invoice
    ->setReason('Returned goods')
    ->setBuyer([/* buyer details */])
    ->addLineItem([
        'name' => 'Returned Product',
        'quantity' => 1,
        'unit_price' => 100.00,
        'vat_category' => VatCategory::STANDARD,
    ])
    ->build();

// Process (automatically clears for standard, reports for simplified)
$result = Zatca::process($creditNote);
```

### Auto-Processing Invoices

The `process()` method automatically determines whether to report or clear:

```php
// Automatically uses report() for simplified invoices
// and clear() for standard invoices
$result = Zatca::process($invoice);

if ($result->wasReported()) {
    // B2C invoice was reported
}

if ($result->wasCleared()) {
    // B2B invoice was cleared
}
```

### VAT Categories

```php
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;

// Standard rate (15%)
->addLineItem([
    'name' => 'Product',
    'quantity' => 1,
    'unit_price' => 100.00,
    'vat_category' => VatCategory::STANDARD,
])

// Zero-rated
->addLineItem([
    'name' => 'Export Product',
    'quantity' => 1,
    'unit_price' => 100.00,
    'vat_category' => VatCategory::ZERO_RATED,
    'vat_exemption_reason' => VatExemptionReason::EXPORT,
])

// Exempt
->addLineItem([
    'name' => 'Financial Service',
    'quantity' => 1,
    'unit_price' => 100.00,
    'vat_category' => VatCategory::EXEMPT,
    'vat_exemption_reason' => VatExemptionReason::FINANCIAL_SERVICES,
])
```

### Working with Certificates

```php
use Corecave\Zatca\Facades\Zatca;

// Check if production certificate exists
if (Zatca::certificate()->hasActiveProductionCertificate()) {
    // Ready to issue invoices
}

// Get certificate details
$cert = Zatca::certificate()->getActive('production');
$expiresAt = $cert->getExpiresAt();
$isExpiringSoon = $cert->isExpiringSoon(30);

// Get certificates expiring soon
$expiring = Zatca::certificate()->getExpiringSoon(30);
```

### Manual XML Generation

```php
use Corecave\Zatca\Facades\Zatca;

// Generate XML without submitting
$xml = Zatca::generateXml($invoice);

// Validate XML
$isValid = Zatca::validateXml($xml);

// Sign XML
$signedXml = Zatca::signXml($xml);
```

### Hash Chain Management

```php
use Corecave\Zatca\Hash\HashChainManager;

$hashManager = app(HashChainManager::class);

// Get next ICV
$icv = $hashManager->getNextIcv();

// Get previous invoice hash
$pih = $hashManager->getPreviousInvoiceHash();

// Verify chain integrity
$result = $hashManager->verifyChain();
if (!$result['valid']) {
    // Chain has issues
}

// Get statistics
$stats = $hashManager->getStatistics();
```

## Artisan Commands

```bash
# Generate CSR for onboarding
php artisan zatca:generate-csr --save

# Run compliance process
php artisan zatca:compliance --otp=123456

# Request production CSID
php artisan zatca:production-csid

# Renew expiring certificate
php artisan zatca:renew-csid --otp=123456
```

## Events

The package dispatches events you can listen to:

- `InvoiceReported` - When a simplified invoice is successfully reported
- `InvoiceCleared` - When a standard invoice is successfully cleared
- `InvoiceRejected` - When an invoice is rejected by ZATCA
- `CertificateExpiring` - When a certificate is about to expire

## Testing

The package includes a sandbox mode for testing:

```php
// In your .env
ZATCA_ENVIRONMENT=sandbox
```

Run the test suite:

```bash
./vendor/bin/pest tests/Feature/ZatcaPackageTest.php
```

## Test Coverage Summary - 66 Tests, 156 Assertions

The package includes comprehensive tests covering all ZATCA e-invoicing functionality:

### CSR Generator (5 tests)
- Valid CSR generation with private/public keys
- B2C-only invoice type configuration
- B2B-only invoice type configuration
- VAT number format validation (15 digits)
- VAT number must start and end with 3

### Invoice Builder - Simplified B2C (4 tests)
- Create simplified invoice with standard VAT calculation
- Multiple line items with totals
- No buyer required for B2C
- Optional buyer allowed

### Invoice Builder - Standard B2B (3 tests)
- Create standard invoice with buyer
- Buyer required validation
- Buyer VAT number validation

### Invoice Builder - Credit Notes (3 tests)
- Simplified credit note with reference
- Standard credit note
- Original invoice reference required

### Invoice Builder - Debit Notes (3 tests)
- Simplified debit note
- Standard debit note
- Original invoice reference required

### Line Item - VAT Categories (4 tests)
- Standard VAT (15%)
- Zero-rated VAT (0%)
- Exempt VAT
- Out-of-scope VAT

### Line Item - Discounts (3 tests)
- Discount calculation
- Invoice total with discounts
- Zero discount handling

### Payment Methods (3 tests)
- Cash payment
- Bank transfer with payment terms
- Bank card payment

### Multi-Currency Support (3 tests)
- Default SAR currency
- USD support
- EUR support

### TLV Encoder (3 tests)
- Encode/decode TLV data
- Arabic text encoding
- All 9 Phase 2 QR tags

### QR Generator (2 tests)
- Phase 1 QR code generation
- QR code decoding and verification

### UBL Generator (6 tests)
- Valid UBL XML generation
- Correct invoice type codes
- Credit note type code (381)
- Seller information in XML
- Buyer information for B2B
- Line items in XML

### XML Validator (4 tests)
- Well-formed XML validation
- Malformed XML rejection
- XML with namespaces
- UBL invoice structure

### XML Signer (2 tests)
- Invoice hash generation
- Consistent hash for same invoice

### Hash Chain Manager (4 tests)
- Initial hash for first invoice
- Unique UUID generation
- UUID format validation
- Hash chain continuity

### Invoice Validation (7 tests)
- Invoice number required
- Seller information required
- Seller VAT number required
- At least one line item required
- Line item name required
- Positive quantity required
- Non-negative unit price required

### Full Invoice Workflow (3 tests)
- Complete simplified invoice flow: build -> XML -> hash -> QR
- Complete standard invoice flow with buyer
- Invoice chain with incrementing ICV

### Additional Features (3 tests)
- Invoice notes
- Supply date different from issue date
- UUID auto-generation and custom UUID

## Error Handling

```php
use Corecave\Zatca\Exceptions\ApiException;
use Corecave\Zatca\Exceptions\ValidationException;
use Corecave\Zatca\Exceptions\CertificateException;

try {
    $result = Zatca::report($invoice);
} catch (ValidationException $e) {
    // Invoice validation failed
    $errors = $e->getErrors();
} catch (ApiException $e) {
    // ZATCA API error
    $zatcaErrors = $e->getZatcaErrors();
    $zatcaWarnings = $e->getZatcaWarnings();
} catch (CertificateException $e) {
    // Certificate issue
}
```

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Resources

- [ZATCA E-Invoicing Portal](https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx)
- [Developer Portal Manual](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/DEVELOPER-PORTAL-MANUAL.pdf)
- [XML Implementation Standard](https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf)
- [ZATCA SDK](https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/ComplianceEnablementToolbox/Pages/DownloadSDK.aspx)
