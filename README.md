# Laravel ZATCA

A comprehensive Laravel package for integrating with Saudi Arabia's ZATCA (Zakat, Tax and Customs Authority) e-invoicing system (FATOORA platform).

## Features

- CSR generation and certificate management
- Invoice XML generation (UBL 2.1 compliant)
- Digital signing with ECDSA (secp256k1)
- QR code generation (TLV format with 9 tags)
- QR code image generation (PNG/SVG) for invoices
- Invoice reporting (B2C simplified invoices)
- Invoice clearance (B2B standard invoices)
- Credit and debit note handling
- Hash chain management (ICV & PIH)
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

# ===========================================
# Seller Information
# ===========================================
ZATCA_SELLER_NAME="Your Company Name"
ZATCA_SELLER_NAME_AR="اسم شركتك بالعربي"

# VAT Number: 15 digits, format: 3XXXXXXXXXX0003
# - Must start with 3
# - Followed by 10-digit registration number
# - Followed by 0003
ZATCA_VAT_NUMBER=310000000000003

# Commercial Registration Number (CRN): 10 digits
ZATCA_REGISTRATION_NUMBER=1000000000

# ===========================================
# Seller Address (Required for SA)
# ===========================================
ZATCA_SELLER_STREET="Main Street"
ZATCA_SELLER_BUILDING="1234"           # 4 digits
ZATCA_SELLER_CITY="Riyadh"
ZATCA_SELLER_DISTRICT="Al Olaya"
ZATCA_SELLER_POSTAL_CODE="12345"       # 5 digits
ZATCA_SELLER_ADDITIONAL_NUMBER="1234"  # 4 digits (optional)

# ===========================================
# CSR Configuration
# ===========================================
ZATCA_CSR_ORGANIZATION="Your Company Name"
ZATCA_CSR_ORGANIZATION_UNIT="Main Branch"
# Common name format depends on environment:
# - Simulation: TST-886431145-{VAT_NUMBER}
# - Production: {VAT_NUMBER}
ZATCA_CSR_COMMON_NAME="TST-886431145-310000000000003"

# Invoice types: 1100 = B2B + B2C, 1000 = B2C only, 0100 = B2B only
ZATCA_INVOICE_TYPES=1100

# Business category
ZATCA_BUSINESS_CATEGORY="Retail"
ZATCA_CITY="Riyadh"

# ===========================================
# Debug (optional, for development)
# ===========================================
ZATCA_DEBUG_ENABLED=true
ZATCA_DEBUG_PATH=zatca/debug
```

## Understanding ZATCA Environments

| Environment | Portal | API Endpoint | Purpose |
|-------------|--------|--------------|---------|
| `sandbox` | Developer Portal | `/developer-portal` | Basic development testing with mock certificates |
| `simulation` | Simulation Portal | `/simulation` | Real testing with ZATCA - invoices are validated but not recorded |
| `production` | FATOORA Portal | `/core` | Live production - invoices are legally binding |

**Important Notes:**
- **Sandbox** uses mock certificates that won't pass ZATCA validators - only for initial development
- **Simulation** uses real ZATCA certificates but invoices aren't recorded - use for integration testing
- **Production** is live - every invoice submitted is legally binding

## Complete Onboarding Process

### Overview

The onboarding process involves 4 steps:

1. **Generate CSR** - Create a Certificate Signing Request
2. **Get Compliance CSID** - Submit CSR with OTP to get a compliance certificate
3. **Pass Compliance Checks** - Submit sample invoices for validation
4. **Get Production CSID** - Exchange compliance certificate for production certificate

### Step 1: Generate CSR

```bash
php artisan zatca:generate-csr --save
```

This generates:
- A private key (stored securely)
- A CSR file for submission to ZATCA

### Step 2: Get Compliance CSID & Run Compliance Checks

1. Log in to the appropriate ZATCA portal:
   - **Simulation**: https://fatoora.zatca.gov.sa/ (simulation section)
   - **Production**: https://fatoora.zatca.gov.sa/ (production section)

2. Navigate to your EGS (e-Invoice Generation Solution) unit

3. Generate a new OTP (One-Time Password)

4. Run the compliance command within 1 hour (OTP expires):

```bash
# For simulation environment
php artisan zatca:compliance --otp=123456

# The command will:
# 1. Submit your CSR to ZATCA
# 2. Receive a compliance certificate (CSID)
# 3. Run compliance checks with sample invoices
# 4. Display pass/fail status for each check
```

### Step 3: Get Production CSID

After **ALL** compliance checks pass, request your production certificate:

```bash
php artisan zatca:production-csid
```

**Important:**
- This command does NOT require a new OTP
- It uses your compliance certificate to authenticate
- The compliance request ID is used to verify you passed compliance
- Only works if you completed Step 2 successfully

### Step 4: Start Issuing Invoices

Once you have a production certificate, you can start issuing legally-binding invoices:

```php
use Corecave\Zatca\Facades\Zatca;

// The package automatically uses your production certificate
$result = Zatca::process($invoice);
```

## Full Usage Example

Here's a complete example from building an invoice to submitting it:

```php
<?php

namespace App\Services;

use Corecave\Zatca\Facades\Zatca;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\PaymentMethod;
use Corecave\Zatca\Models\ZatcaInvoice;

class InvoiceService
{
    /**
     * Create and submit a B2C invoice to ZATCA.
     */
    public function createSimplifiedInvoice(array $orderData): ZatcaInvoice
    {
        // Step 1: Build the invoice
        $invoice = InvoiceBuilder::simplified()
            ->setInvoiceNumber('INV-' . date('Y') . '-' . str_pad($orderData['id'], 6, '0', STR_PAD_LEFT))
            ->setIssueDate(now())
            ->setSupplyDate(now())
            ->setPaymentMethod(PaymentMethod::CASH);

        // Step 2: Add line items
        foreach ($orderData['items'] as $item) {
            $invoice->addLineItem([
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['price'],  // Price EXCLUDING VAT
                'vat_category' => VatCategory::STANDARD,  // 15% VAT
            ]);
        }

        // Step 3: Build and submit to ZATCA
        $builtInvoice = $invoice->build();
        $result = Zatca::report($builtInvoice);  // B2C uses report()

        // Step 4: Handle the result
        if ($result->isSuccess()) {
            // Get the stored invoice record
            $zatcaInvoice = ZatcaInvoice::where('uuid', $builtInvoice->getUuid())->first();

            // QR code for printing on receipt
            $qrCodeTlv = $result->getQrCode();

            // QR code as PNG for embedding in emails/PDFs
            $qrCodePng = $zatcaInvoice->qr_code_image;

            return $zatcaInvoice;
        }

        // Handle errors
        throw new \Exception('ZATCA submission failed: ' . json_encode($result->getErrors()));
    }

    /**
     * Create and submit a B2B invoice to ZATCA.
     */
    public function createStandardInvoice(array $orderData, array $buyerData): ZatcaInvoice
    {
        // Step 1: Build the invoice with buyer information
        $invoice = InvoiceBuilder::standard()
            ->setInvoiceNumber('INV-' . date('Y') . '-' . str_pad($orderData['id'], 6, '0', STR_PAD_LEFT))
            ->setIssueDate(now())
            ->setSupplyDate(now())
            ->setPaymentMethod(PaymentMethod::CREDIT)
            ->setBuyer([
                'name' => $buyerData['company_name'],
                'vat_number' => $buyerData['vat_number'],
                'registration_number' => $buyerData['cr_number'],
                'registration_scheme' => 'CRN',
                'address' => [
                    'street' => $buyerData['street'],
                    'building' => $buyerData['building'],
                    'city' => $buyerData['city'],
                    'district' => $buyerData['district'],
                    'postal_code' => $buyerData['postal_code'],
                    'country' => 'SA',
                ],
            ]);

        // Step 2: Add line items
        foreach ($orderData['items'] as $item) {
            $invoice->addLineItem([
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['price'],
                'vat_category' => VatCategory::STANDARD,
            ]);
        }

        // Step 3: Build and submit to ZATCA
        $builtInvoice = $invoice->build();
        $result = Zatca::clear($builtInvoice);  // B2B uses clear()

        // Step 4: Handle the result
        if ($result->isSuccess()) {
            return ZatcaInvoice::where('uuid', $builtInvoice->getUuid())->first();
        }

        throw new \Exception('ZATCA clearance failed: ' . json_encode($result->getErrors()));
    }

    /**
     * Auto-detect invoice type and submit.
     */
    public function submitInvoice($invoice): ZatcaInvoice
    {
        // process() automatically uses report() for B2C and clear() for B2B
        $result = Zatca::process($invoice);

        if ($result->wasReported()) {
            // B2C invoice was reported
        }

        if ($result->wasCleared()) {
            // B2B invoice was cleared
        }

        return ZatcaInvoice::where('uuid', $invoice->getUuid())->first();
    }
}
```

## VAT Number & Registration Number Formats

### VAT Number (15 digits)

Format: `3XXXXXXXXXX0003`

| Position | Value | Description |
|----------|-------|-------------|
| 1 | `3` | Always 3 (country code for Saudi Arabia) |
| 2-11 | `XXXXXXXXXX` | Your 10-digit Commercial Registration Number |
| 12-15 | `0003` | Fixed suffix |

**Example:** If your CR is `1234567890`, your VAT number is `312345678900003`

### Commercial Registration Number (10 digits)

This is your company's official registration number from the Ministry of Commerce.

**Example:** `1234567890`

## Buyer Identification Schemes

When specifying buyer information for B2B invoices, use the appropriate scheme:

| Scheme ID | Description | Format |
|-----------|-------------|--------|
| `CRN` | Commercial Registration Number | 10 digits |
| `MOM` | Momra License | Variable |
| `MLS` | MLSD License | Variable |
| `SAG` | Sagia License | Variable |
| `NAT` | National ID | 10 digits |
| `GCC` | GCC ID | Variable |
| `IQA` | Iqama Number | 10 digits |
| `TIN` | Tax Identification Number (VAT) | 15 digits |
| `700` | 700 Number | Variable |
| `OTH` | Other ID | Variable |

## Creating Credit Notes (Refunds)

```php
use Corecave\Zatca\Invoice\InvoiceBuilder;

// Credit note for a B2C refund
$creditNote = InvoiceBuilder::creditNote(simplified: true)
    ->setInvoiceNumber('CN-2024-001')
    ->setOriginalInvoice('INV-2024-001')  // Reference the original invoice
    ->setReason('Customer returned goods')
    ->addLineItem([
        'name' => 'Returned Product',
        'quantity' => 1,
        'unit_price' => 100.00,
        'vat_category' => VatCategory::STANDARD,
    ])
    ->build();

$result = Zatca::process($creditNote);
```

## QR Code Image Generation

Generate QR code images for receipts, emails, or PDFs:

```php
use Corecave\Zatca\Models\ZatcaInvoice;

$invoice = ZatcaInvoice::find($id);

// Get QR code as base64-encoded PNG (for emails/PDFs)
$pngBase64 = $invoice->qr_code_image;
echo '<img src="data:image/png;base64,' . $pngBase64 . '" alt="QR Code">';

// Get QR code as SVG (for web display)
$svg = $invoice->qr_code_svg;
echo $svg;

// Get raw TLV data (for custom QR generation)
$tlvData = $invoice->qr_code;
```

**Note:** Requires `simplesoftwareio/simple-qrcode` package.

## Artisan Commands

### Generate CSR
```bash
php artisan zatca:generate-csr --save
```

### Run Compliance Process
```bash
# Get OTP from ZATCA portal first, then:
php artisan zatca:compliance --otp=123456
```

### Get Production CSID
```bash
# After compliance passes (no OTP needed):
php artisan zatca:production-csid

# Or specify request ID manually:
php artisan zatca:production-csid --request-id=1234567890
```

### Renew Production Certificate
```bash
# Get new OTP from ZATCA portal, then:
php artisan zatca:renew-csid --otp=123456

# Force renewal even if not expiring:
php artisan zatca:renew-csid --otp=123456 --force
```

### Cleanup Utility
```bash
# Show help
php artisan zatca:cleanup

# Clean up compliance certificates
php artisan zatca:cleanup --compliance

# Clean up production certificates
php artisan zatca:cleanup --production

# Clean up all certificates
php artisan zatca:cleanup --certificates

# Clean up CSR and private key files
php artisan zatca:cleanup --csr

# Clean up all invoices from database
php artisan zatca:cleanup --invoices

# Clean up debug files
php artisan zatca:cleanup --debug

# Clean up everything
php artisan zatca:cleanup --all

# Skip confirmation prompts
php artisan zatca:cleanup --all --force
```

## Debugging

Enable debug mode to save XML files for inspection:

```env
ZATCA_DEBUG_ENABLED=true
ZATCA_DEBUG_PATH=zatca/debug
```

Debug files are saved to `storage/app/zatca/debug/`:
- `{invoice}_unsigned.xml` - XML before signing
- `{invoice}_signed.xml` - XML after signing
- `{invoice}_hash.txt` - Invoice hash
- `{invoice}_qr.txt` - QR code TLV data

## Error Handling

```php
use Corecave\Zatca\Exceptions\ApiException;
use Corecave\Zatca\Exceptions\ValidationException;
use Corecave\Zatca\Exceptions\CertificateException;

try {
    $result = Zatca::report($invoice);

    if (!$result->isSuccess()) {
        // ZATCA accepted but with warnings
        $warnings = $result->getWarnings();
    }
} catch (ValidationException $e) {
    // Invoice validation failed locally
    $errors = $e->getErrors();
} catch (ApiException $e) {
    // ZATCA API returned an error
    $zatcaErrors = $e->getZatcaErrors();
    $zatcaWarnings = $e->getZatcaWarnings();
} catch (CertificateException $e) {
    // Certificate issue (missing, expired, invalid)
    $message = $e->getMessage();
}
```

## Common ZATCA Validation Errors

| Error Code | Message | Solution |
|------------|---------|----------|
| `BR-KSA-F-13` | Invalid Seller/Buyer ID | Check VAT number format (15 digits: 3XXXXXXXXXX0003) |
| `BR-KSA-63` | Missing buyer address fields | Include all required fields for SA buyers |
| `BR-KSA-18` | Invalid building number | Building number must be exactly 4 digits |
| `BR-KSA-64` | Invalid additional number | Additional number must be exactly 4 digits |
| `X509IssuerName` | Wrong certificate issuer | Use simulation/production environment, not sandbox |
| `Invalid-CSR` | CSR is invalid | Regenerate CSR with correct configuration |

## Events

The package dispatches events you can listen to:

```php
// In EventServiceProvider
protected $listen = [
    \Corecave\Zatca\Events\InvoiceReported::class => [
        \App\Listeners\HandleInvoiceReported::class,
    ],
    \Corecave\Zatca\Events\InvoiceCleared::class => [
        \App\Listeners\HandleInvoiceCleared::class,
    ],
    \Corecave\Zatca\Events\InvoiceRejected::class => [
        \App\Listeners\HandleInvoiceRejected::class,
    ],
];
```

## Changelog

### v1.2.0 (2024-12-16)

#### Added
- QR code image generation (`qr_code_image` and `qr_code_svg` attributes)
- Optional dependency on `simplesoftwareio/simple-qrcode`

### v1.1.0 (2024-12-13)

#### Fixed
- X509IssuerName format matching ZATCA SDK
- SignedProperties hash computation
- Buyer PartyIdentification with proper schemeID
- SA buyer address fields (BuildingNumber, District, etc.)

#### Added
- Compliance check command with sample invoice generation
- Certificate cleanup command
- Automatic schemeID detection

### v1.0.0 (2024-12-12)

- Initial release with full Phase 2 support

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Resources

- [ZATCA E-Invoicing Portal](https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx)
- [FATOORA Portal](https://fatoora.zatca.gov.sa/)
- [ZATCA XML Validator](https://sandbox.zatca.gov.sa/Compliance)
- [Developer Portal Manual](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/DEVELOPER-PORTAL-MANUAL.pdf)
- [XML Implementation Standard](https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf)
