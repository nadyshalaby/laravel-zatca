<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Illuminate\Console\Command;

/**
 * Run ZATCA compliance checks using an existing compliance certificate.
 *
 * This command allows you to re-run compliance checks without requesting
 * a new compliance certificate (which requires a new OTP).
 */
class ComplianceCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:compliance-check
                            {--type=all : Invoice type to test (all, simplified, standard)}
                            {--save-xml : Save generated XML files for debugging}
                            {--verbose-errors : Show all validation messages including warnings}';

    /**
     * The console command description.
     */
    protected $description = 'Run ZATCA compliance checks using existing compliance certificate';

    /**
     * Execute the console command.
     */
    public function handle(
        CertificateManager $certManager,
        ZatcaClient $client,
        UblGenerator $xmlGenerator,
        XmlSigner $signer,
        QrGenerator $qrGenerator
    ): int {
        $this->info('ZATCA Compliance Check');
        $this->info('======================');
        $this->newLine();

        // Load existing compliance certificate
        $certificate = $certManager->getActive('compliance');

        if (! $certificate) {
            $this->error('No active compliance certificate found.');
            $this->newLine();
            $this->warn('You need to run `php artisan zatca:compliance` first to obtain a compliance certificate.');

            return self::FAILURE;
        }

        $this->info('Loaded compliance certificate.');
        $this->components->twoColumnDetail('Certificate Type', $certificate->getType());
        $this->components->twoColumnDetail('Expires', $certificate->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'N/A');
        $this->newLine();

        // Set certificate on client
        $client->setCertificate($certificate);

        // Run compliance checks
        $this->info('Running compliance checks...');
        $this->newLine();

        $result = $this->runComplianceChecks(
            $client,
            $xmlGenerator,
            $signer,
            $qrGenerator,
            $certificate
        );

        $this->newLine();

        if ($result['failed'] === 0) {
            $this->info('All compliance checks passed!');

            return self::SUCCESS;
        } else {
            $this->error("Compliance checks failed: {$result['failed']} of {$result['total']} failed.");

            return self::FAILURE;
        }
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
    ): array {
        $invoiceTypes = config('zatca.csr.invoice_types', '1100');
        $typeFilter = $this->option('type');

        $sampleInvoices = [];

        // B2C samples (simplified)
        if (in_array($invoiceTypes, ['1000', '1100']) && in_array($typeFilter, ['all', 'simplified'])) {
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'debit_note'];
        }

        // B2B samples (standard)
        if (in_array($invoiceTypes, ['0100', '1100']) && in_array($typeFilter, ['all', 'standard'])) {
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'debit_note'];
        }

        if (empty($sampleInvoices)) {
            $this->warn('No invoice types to test based on configuration and filters.');

            return ['passed' => 0, 'failed' => 0, 'total' => 0];
        }

        $passed = 0;
        $failed = 0;

        foreach ($sampleInvoices as $index => $sample) {
            $label = ucfirst($sample['type']) . ' ' . str_replace('_', ' ', $sample['subtype']);
            $this->line("Testing: {$label}");

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

                // Generate QR code
                $signatureValue = $signer->getSignatureValue($signedXml);
                $publicKeyDer = $certificate->getPublicKeyRaw();
                $certificateSignature = $certificate->getCertificateSignature();
                $qrCode = $qrGenerator->generate($invoice, $signatureValue, $publicKeyDer, $certificateSignature);

                // Add QR code to XML
                $signedXml = $xmlGenerator->addQrCode($signedXml, $qrCode);

                // Save XML if requested
                if ($this->option('save-xml')) {
                    $filename = storage_path("zatca/debug/{$sample['type']}_{$sample['subtype']}.xml");
                    if (! is_dir(dirname($filename))) {
                        mkdir(dirname($filename), 0755, true);
                    }
                    file_put_contents($filename, $signedXml);
                    $this->line("  Saved: {$filename}");
                }

                // Submit for compliance check
                $response = $client->submitComplianceInvoice(
                    $signedXml,
                    $hash,
                    $invoice->getUuid()
                );

                $status = $response['validationResults']['status'] ?? 'UNKNOWN';

                if (strtoupper($status) === 'PASS') {
                    $this->info("  Result: PASS");
                    $passed++;
                } elseif (strtoupper($status) === 'WARNING') {
                    $this->warn("  Result: PASS (with warnings)");
                    $this->displayValidationMessages($response, 'warning');
                    $passed++;
                } else {
                    $this->error("  Result: FAIL ({$status})");
                    $this->displayValidationMessages($response, 'error');
                    $failed++;
                }

                // Show warnings if verbose
                if ($this->option('verbose-errors')) {
                    $this->displayValidationMessages($response, 'all');
                }
            } catch (\Exception $e) {
                $this->error("  ERROR: {$e->getMessage()}");
                $failed++;
            }

            $this->newLine();
        }

        $this->components->twoColumnDetail('Passed', (string) $passed);
        $this->components->twoColumnDetail('Failed', (string) $failed);
        $this->components->twoColumnDetail('Total', (string) ($passed + $failed));

        return [
            'passed' => $passed,
            'failed' => $failed,
            'total' => $passed + $failed,
        ];
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
            $builder->setReason('Cancellation or Returned');
        } elseif ($isDebit) {
            $builder = InvoiceBuilder::debitNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Price adjustment or Additional charges');
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
                'registration_scheme' => 'CRN',
                'address' => [
                    'street' => 'King Fahd Road',
                    'building' => '1234',
                    'additional_number' => '5678',
                    'city' => 'Riyadh',
                    'district' => 'Al Olaya',
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
     * Display validation messages from response.
     */
    protected function displayValidationMessages(array $response, string $level = 'error'): void
    {
        $results = $response['validationResults'] ?? [];

        // Display info messages
        if ($level === 'all' && ! empty($results['infoMessages'])) {
            foreach ($results['infoMessages'] as $msg) {
                $this->line("    <fg=blue>INFO:</> {$msg['message']} ({$msg['code']})");
            }
        }

        // Display warnings
        if (in_array($level, ['all', 'warning']) && ! empty($results['warningMessages'])) {
            foreach ($results['warningMessages'] as $msg) {
                $this->line("    <fg=yellow>WARNING:</> {$msg['message']} ({$msg['code']})");
            }
        }

        // Display errors
        if (in_array($level, ['all', 'error']) && ! empty($results['errorMessages'])) {
            foreach ($results['errorMessages'] as $msg) {
                $this->line("    <fg=red>ERROR:</> {$msg['message']} ({$msg['code']})");
            }
        }
    }
}
