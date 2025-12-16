# Laravel ZATCA

A comprehensive Laravel package for integrating with Saudi Arabia's ZATCA (Zakat, Tax and Customs Authority) e-invoicing system (FATOORA platform).

This package supports Phase 2 requirements including:
- CSR generation and certificate management
- Invoice XML generation (UBL 2.1 compliant)
- Digital signing with ECDSA (secp256k1)
- QR code generation (TLV format with 9 tags)
- QR code image generation (PNG/SVG) for invoices
- Invoice reporting (B2C simplified invoices)
- Invoice clearance (B2B standard invoices)
- Credit and debit note handling
- Sandbox, simulation, and production environments

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- OpenSSL extension
- GMP extension (recommended for better performance)
- `simplesoftwareio/simple-qrcode` (optional, for QR code image generation)

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

### Optional: QR Code Image Generation

To generate QR code images (PNG/SVG) for embedding in emails or PDFs:

```bash
composer require simplesoftwareio/simple-qrcode
```

## Configuration

Add these environment variables to your `.env` file:

```env
# Environment: sandbox, simulation, or production
ZATCA_ENVIRONMENT=simulation

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

# Debug (optional)
ZATCA_DEBUG_ENABLED=true
ZATCA_DEBUG_PATH=zatca/debug
```

## Environments

The package supports three environments:

| Environment | Endpoint | Certificate Issuer | Purpose |
|-------------|----------|-------------------|---------|
| `sandbox` | developer-portal | `CN=eInvoicing` (mock) | Basic development testing |
| `simulation` | simulation | `CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local` | Real testing with ZATCA |
| `production` | core | `CN=PEZEINVOICESCA2-CA, DC=extgazt, DC=gov, DC=local` | Live production |

**Important:**
- The `sandbox` environment returns mock certificates that will NOT pass ZATCA's official validators
- Use `simulation` for testing with the ZATCA validator at https://sandbox.zatca.gov.sa/Compliance
- Use `production` only when ready to go live

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

This command will:
- Request a compliance CSID from ZATCA
- Run compliance checks with sample invoices (simplified & standard)
- Display pass/fail status for each check

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
    $qrCode = $result->getQrCode();      // TLV base64 string
    $signedXml = $result->getSignedXml();

    // Store or display the QR code
}
```

### Creating a Standard Invoice (B2B)

For standard B2B invoices, buyer information is **required** and must include complete address details for Saudi Arabian buyers.

```php
use Corecave\Zatca\Facades\Zatca;
use Corecave\Zatca\Invoice\InvoiceBuilder;

$invoice = InvoiceBuilder::standard()
    ->setInvoiceNumber('INV-2024-002')
    ->setIssueDate(now())
    ->setBuyer([
        'name' => 'Buyer Company LLC',
        'vat_number' => '300000000000003',        // Used for TIN scheme if no registration_number
        'registration_number' => '1234567890',   // CRN - Commercial Registration Number
        'registration_scheme' => 'CRN',          // Optional: CRN, MOM, MLS, SAG, 700, OTH
        'address' => [
            'street' => 'King Fahd Road',
            'building' => '1234',                // Required for SA buyers (KSA-18)
            'additional_number' => '5678',       // Optional (KSA-23)
            'city' => 'Riyadh',
            'district' => 'Al Olaya',            // Required for SA buyers (KSA-4)
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

### Buyer Identification Schemes

For the `registration_scheme` field, ZATCA accepts:

| Scheme ID | Description |
|-----------|-------------|
| `CRN` | Commercial Registration Number |
| `MOM` | Momra License |
| `MLS` | MLSD License |
| `SAG` | Sagia License |
| `NAT` | National ID (10 digits) |
| `GCC` | GCC ID |
| `IQA` | Iqama Number |
| `TIN` | Tax Identification Number (VAT) |
| `700` | 700 Number |
| `OTH` | Other ID |

### Required Buyer Address Fields for Saudi Arabia (BR-KSA-63)

When the buyer's country is `SA`, these fields are **mandatory**:

| Field | XML Element | Description |
|-------|-------------|-------------|
| `street` | `cbc:StreetName` | Street name (BT-50) |
| `building` | `cbc:BuildingNumber` | Building number - 4 digits (KSA-18) |
| `postal_code` | `cbc:PostalZone` | Postal code - 5 digits (BT-53) |
| `city` | `cbc:CityName` | City name (BT-52) |
| `district` | `cbc:CitySubdivisionName` | District name (KSA-4) |
| `country` | `cbc:IdentificationCode` | Country code (BT-55) |

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

### QR Code Image Generation

The `ZatcaInvoice` model provides methods to generate QR code images for embedding in emails, PDFs, or displaying on screen.

```php
use Corecave\Zatca\Models\ZatcaInvoice;

$invoice = ZatcaInvoice::find($id);

// Get QR code as base64-encoded PNG image
$pngBase64 = $invoice->qr_code_image;

// Use in HTML
echo '<img src="data:image/png;base64,' . $pngBase64 . '" alt="QR Code">';

// Get QR code as SVG string
$svg = $invoice->qr_code_svg;

// Use SVG directly in HTML
echo $svg;
```

**Note:** QR code image generation requires the `simplesoftwareio/simple-qrcode` package:

```bash
composer require simplesoftwareio/simple-qrcode
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

// Get certificate issuer (for debugging)
$issuer = $cert->getFormattedIssuer();
// Should be: CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local (simulation)
// NOT: CN=eInvoicing (mock/sandbox)
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

# Run compliance process (requests CSID + runs compliance checks)
php artisan zatca:compliance --otp=123456

# Request production CSID (after compliance passes)
php artisan zatca:production-csid

# Renew expiring certificate
php artisan zatca:renew-csid --otp=123456

# Clean up old/orphaned certificates
php artisan zatca:cleanup-certificates --days=90
```

## Events

The package dispatches events you can listen to:

- `InvoiceReported` - When a simplified invoice is successfully reported
- `InvoiceCleared` - When a standard invoice is successfully cleared
- `InvoiceRejected` - When an invoice is rejected by ZATCA
- `CertificateExpiring` - When a certificate is about to expire

## Debugging

Enable debug mode to save XML files and hashes for inspection:

```env
ZATCA_DEBUG_ENABLED=true
ZATCA_DEBUG_PATH=zatca/debug
```

Debug files are saved to `storage/app/{ZATCA_DEBUG_PATH}/`:
- `{invoice}_unsigned.xml` - XML before signing
- `{invoice}_signed.xml` - XML after signing
- `{invoice}_hash.txt` - Invoice hash
- `{invoice}_qr.txt` - QR code data

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

## Common ZATCA Validation Errors

| Error Code | Message | Solution |
|------------|---------|----------|
| `X509IssuerName` | wrong X509IssuerName | Use `simulation` or `production` environment to get real certificates |
| `X509SerialNumber` | wrong X509SerialNumber | Certificate not from ZATCA - regenerate with real OTP |
| `BR-KSA-F-13` | Invalid Seller/Buyer ID | Provide valid `registration_number` with correct `registration_scheme` |
| `BR-KSA-63` | Missing address fields | Include all required fields: street, building, postal_code, city, district |
| `BR-CL-KSA-14` | QR Code exceeds 1000 chars | Reduce invoice data or check QR generation |

## Changelog

### v1.2.0 (2024-12-16)

#### Added
- **QR Code Image Generation**: New `qr_code_image` and `qr_code_svg` attributes on `ZatcaInvoice` model for generating QR code images (PNG/SVG) suitable for embedding in emails and PDFs
- Optional dependency on `simplesoftwareio/simple-qrcode` for QR code image generation

### v1.1.0 (2024-12-13)

#### Fixed
- **X509IssuerName format**: Now correctly uses RFC 4514 format with `, ` (comma + space) separators matching ZATCA SDK
- **X509SerialNumber**: Properly extracted from certificate
- **SignedProperties hash**: XML whitespace now matches Python SDK's exact format for correct hash computation
- **Buyer PartyIdentification**: Now always includes `<cac:PartyIdentification>` element with proper schemeID
- **Buyer postal address**: Now includes all required SA address fields (BuildingNumber, District, etc.)

#### Changed
- `Certificate::getFormattedIssuer()` no longer reverses DN order
- `XmlSigner::formatIssuerDN()` preserves `, ` separators
- `XmlSigner::computeSignedPropertiesHashSdkStyle()` uses exact SDK whitespace
- `XmlSigner::buildSignatureXml()` matches SDK indentation structure
- `UblGenerator::addCustomerParty()` always adds buyer ID, uses VAT as TIN fallback

#### Added
- Support for flat buyer data with all SA-required address fields
- Automatic schemeID detection (TIN for VAT numbers, NAT for national IDs, CRN for registration numbers)
- Compliance check command with automatic sample invoice generation
- Certificate cleanup command for removing old/orphaned certificates

### v1.0.0 (2024-12-12)

- Initial release with full Phase 2 support

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Resources

- [ZATCA E-Invoicing Portal](https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx)
- [ZATCA Simulation Portal](https://fatoora.zatca.gov.sa/)
- [ZATCA XML Validator](https://sandbox.zatca.gov.sa/Compliance)
- [Developer Portal Manual](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/DEVELOPER-PORTAL-MANUAL.pdf)
- [XML Implementation Standard](https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf)
- [ZATCA SDK](https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/ComplianceEnablementToolbox/Pages/DownloadSDK.aspx)
