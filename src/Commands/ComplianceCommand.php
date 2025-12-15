<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Illuminate\Console\Command;

class ComplianceCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:compliance
                            {--otp= : OTP from FATOORA portal}
                            {--csr= : Path to CSR file}
                            {--private-key= : Path to private key file}
                            {--skip-checks : Skip compliance invoice checks}';

    /**
     * The console command description.
     */
    protected $description = 'Complete ZATCA compliance onboarding process';

    /**
     * Execute the console command.
     */
    public function handle(
        CsrGenerator $csrGenerator,
        CertificateManager $certManager,
        ZatcaClient $client,
        UblGenerator $xmlGenerator,
        XmlSigner $signer,
        QrGenerator $qrGenerator
    ): int {
        $this->info('Starting ZATCA Compliance Process...');
        $this->newLine();

        // Get OTP
        $otp = $this->option('otp');
        if (empty($otp)) {
            $otp = $this->ask('Enter OTP from FATOORA portal');
        }

        // Get or generate CSR
        $csrData = $this->getCsrData($csrGenerator);

        if (! $csrData) {
            return self::FAILURE;
        }

        // Step 1: Request Compliance CSID
        $this->info('Step 1: Requesting Compliance CSID...');

        try {
            $response = $client->requestComplianceCsid($csrData['csr'], $otp);

            $this->info('Compliance CSID received!');
            $this->components->twoColumnDetail('Request ID', $response['requestID'] ?? 'N/A');
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('Failed to get Compliance CSID: '.$e->getMessage());

            return self::FAILURE;
        }

        // Store compliance certificate
        $complianceCert = Certificate::fromApiResponse($response, $csrData['private_key'], 'compliance');
        $certManager->store($complianceCert);

        $this->info('Compliance certificate stored.');
        $this->newLine();

        // Step 2: Run compliance checks
        if (! $this->option('skip-checks')) {
            $this->info('Step 2: Running compliance checks...');

            $client->setCertificate($complianceCert);

            $checksResult = $this->runComplianceChecks(
                $client,
                $xmlGenerator,
                $signer,
                $qrGenerator,
                $complianceCert
            );

            if (! $checksResult) {
                $this->error('Compliance checks failed.');

                return self::FAILURE;
            }
        } else {
            $this->warn('Skipping compliance checks (--skip-checks)');
        }

        $this->newLine();
        $this->info('Compliance process completed successfully!');
        $this->newLine();

        $this->warn('Next steps:');
        $this->line('  Run: php artisan zatca:production-csid');
        $this->line('  to request your Production CSID');

        return self::SUCCESS;
    }

    /**
     * Get CSR data from file or generate new.
     */
    protected function getCsrData(CsrGenerator $generator): ?array
    {
        $csrPath = $this->option('csr');
        $keyPath = $this->option('private-key');

        if ($csrPath && $keyPath) {
            if (! file_exists($csrPath) || ! file_exists($keyPath)) {
                $this->error('CSR or private key file not found');

                return null;
            }

            return [
                'csr' => file_get_contents($csrPath),
                'private_key' => file_get_contents($keyPath),
            ];
        }

        // Check for stored CSR
        $storedCsr = storage_path('zatca/csr.pem');
        $storedKey = storage_path('zatca/private_key.pem');

        if (file_exists($storedCsr) && file_exists($storedKey)) {
            if ($this->confirm('Use existing CSR from storage?', true)) {
                return [
                    'csr' => file_get_contents($storedCsr),
                    'private_key' => file_get_contents($storedKey),
                ];
            }
        }

        // Generate new CSR
        $this->warn('No CSR found. Generating new CSR...');
        $this->call('zatca:generate-csr', ['--save' => true]);

        if (file_exists($storedCsr) && file_exists($storedKey)) {
            return [
                'csr' => file_get_contents($storedCsr),
                'private_key' => file_get_contents($storedKey),
            ];
        }

        return null;
    }

    /**
     * Run compliance checks by submitting sample invoices.
     */
    protected function runComplianceChecks(
        ZatcaClient $client,
        UblGenerator $xmlGenerator,
        XmlSigner $signer,
        QrGenerator $qrGenerator,
        Certificate $certificate
    ): bool {
        $invoiceTypes = config('zatca.csr.invoice_types', '1100');

        $sampleInvoices = [];

        // B2C samples (if supported)
        if (in_array($invoiceTypes, ['1000', '1100'])) {
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'debit_note'];
        }

        // B2B samples (if supported)
        if (in_array($invoiceTypes, ['0100', '1100'])) {
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'debit_note'];
        }

        $passed = 0;
        $failed = 0;

        foreach ($sampleInvoices as $index => $sample) {
            $this->line("  Submitting {$sample['type']} {$sample['subtype']}...");

            try {
                // Build sample invoice
                $invoice = $this->buildSampleInvoice($sample, $index + 1);

                // Generate XML
                $xml = $xmlGenerator->generate($invoice);

                // Generate hash and sign
                $hash = $signer->generateInvoiceHash($xml);
                $invoice->setHash($hash);

                $signedXml = $signer->sign(
                    $xml,
                    $certificate->getPrivateKey(),
                    $certificate->getCertificatePem()
                );

                // Generate QR code for simplified (B2C) invoices
                // QR code is also needed for compliance check invoices
                $signatureValue = $signer->getSignatureValue($signedXml);
                $publicKeyDer = $certificate->getPublicKeyRaw();
                $certificateSignature = $certificate->getCertificateSignature();
                $qrCode = $qrGenerator->generate($invoice, $signatureValue, $publicKeyDer, $certificateSignature);

                // Add QR code to XML
                $signedXml = $xmlGenerator->addQrCode($signedXml, $qrCode);

                // Submit for compliance check
                $response = $client->submitComplianceInvoice(
                    $signedXml,
                    $hash,
                    $invoice->getUuid()
                );

                $status = $response['validationResults']['status'] ?? 'UNKNOWN';

                if (strtoupper($status) === 'PASS') {
                    $this->info('    PASS');
                    $passed++;
                } else {
                    $this->error("    FAIL: {$status}");
                    $this->displayValidationErrors($response);
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("    ERROR: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Passed', (string) $passed);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        return $failed === 0;
    }

    /**
     * Build a sample invoice for compliance testing.
     */
    protected function buildSampleInvoice(array $sample, int $number): \Corecave\Zatca\Contracts\InvoiceInterface
    {
        $isSimplified = $sample['type'] === 'simplified';
        $isCredit = $sample['subtype'] === 'credit_note';
        $isDebit = $sample['subtype'] === 'debit_note';

        if ($isCredit) {
            $builder = InvoiceBuilder::creditNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Compliance test credit note');
        } elseif ($isDebit) {
            $builder = InvoiceBuilder::debitNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Compliance test debit note');
        } else {
            $builder = $isSimplified
                ? InvoiceBuilder::simplified()
                : InvoiceBuilder::standard();
        }

        $builder->setInvoiceNumber("SME0000{$number}");

        // Add buyer for standard invoices
        if (! $isSimplified) {
            $builder->setBuyer([
                'name' => 'Test Buyer Company',
                'vat_number' => '300000000000003',
                'registration_number' => '1234567890',
                'address' => [
                    'street' => 'Test Street',
                    'building' => '1234',
                    'city' => 'Riyadh',
                    'district' => 'Test District',
                    'postal_code' => '12345',
                    'country' => 'SA',
                ],
            ]);
        }

        $builder->addLineItem([
            'name' => 'Test Product',
            'quantity' => 1,
            'unit_price' => 100.00,
            'vat_category' => VatCategory::STANDARD,
            'vat_rate' => 15.00,
        ]);

        return $builder->build();
    }

    /**
     * Display validation errors from response.
     */
    protected function displayValidationErrors(array $response): void
    {
        $errors = $response['validationResults']['errorMessages'] ?? [];

        foreach ($errors as $error) {
            $this->line("      - {$error['message']} ({$error['code']})");
        }
    }
}
