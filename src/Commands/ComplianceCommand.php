<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Hash\HashChainManager;
use Corecave\Zatca\Invoice\ComplianceSample;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Xml\InvoiceSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ComplianceCommand extends Command
{
    use AsksForOtp, ChoosesDevice, ReportsFailures;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:compliance
                            {--device= : The ZATCA device being onboarded}
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
    public function handle(CertificateManager $certManager, ZatcaClient $client, InvoiceSigner $invoiceSigner): int
    {
        $this->info('Starting ZATCA Compliance Process...');
        $this->newLine();
        $certManager = $this->deviceCertificates($certManager);

        if ($certManager === null) {
            return self::FAILURE;
        }

        $otp = $this->otp();

        // Get or generate CSR
        $csrData = $this->getCsrData();

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
            $this->reportFailure('Failed to get Compliance CSID: ', $e);

            return self::FAILURE;
        }

        // Store compliance certificate
        $complianceCert = Certificate::fromApiResponse($response, $csrData['private_key'], 'compliance');
        $certManager->store($complianceCert);

        return $this->completeCompliance($client, $invoiceSigner, $complianceCert);
    }

    /**
     * Run the compliance checks unless they are skipped, and show the next steps.
     */
    protected function completeCompliance(ZatcaClient $client, InvoiceSigner $invoiceSigner, Certificate $complianceCert): int
    {
        $this->info('Compliance certificate stored.');
        $this->newLine();

        // Step 2: Run compliance checks
        if (! $this->option('skip-checks')) {
            $this->info('Step 2: Running compliance checks...');

            $client->setCertificate($complianceCert);

            $checksResult = $this->runComplianceChecks($client, $invoiceSigner, $complianceCert);

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
     *
     * @return array{csr: string, private_key: string}|null
     */
    protected function getCsrData(): ?array
    {
        $csrPath = $this->option('csr');
        $keyPath = $this->option('private-key');

        if ($csrPath && $keyPath) {
            if (! file_exists($csrPath) || ! file_exists($keyPath)) {
                $this->error('CSR or private key file not found');

                return null;
            }

            return $this->readCsrData($csrPath, $keyPath);
        }

        // Check for stored CSR
        $storedCsr = storage_path('zatca/csr.pem');
        $storedKey = storage_path('zatca/private_key.pem');

        if (file_exists($storedCsr) && file_exists($storedKey)) {
            if ($this->confirm('Use existing CSR from storage?', true)) {
                return $this->readCsrData($storedCsr, $storedKey);
            }
        }

        // Generate new CSR
        $this->warn('No CSR found. Generating new CSR...');
        $this->call('zatca:generate-csr', ['--save' => true]);

        if (file_exists($storedCsr) && file_exists($storedKey)) {
            return $this->readCsrData($storedCsr, $storedKey);
        }

        return null;
    }

    /**
     * Read a CSR and its private key from their files, or null when either cannot be read.
     *
     * @return array{csr: string, private_key: string}|null
     */
    protected function readCsrData(string $csrPath, string $keyPath): ?array
    {
        $csr = file_get_contents($csrPath);
        $privateKey = file_get_contents($keyPath);

        if ($csr === false || $privateKey === false) {
            $this->error('CSR or private key file cannot be read');

            return null;
        }

        return ['csr' => $csr, 'private_key' => $privateKey];
    }

    /**
     * Run compliance checks by submitting sample invoices.
     */
    protected function runComplianceChecks(ZatcaClient $client, InvoiceSigner $invoiceSigner, Certificate $certificate): bool
    {
        $passed = 0;
        $failed = 0;

        foreach ($this->complianceSamples() as $index => $sample) {
            $this->line("  Submitting {$sample['type']} {$sample['subtype']}...");

            try {
                // Build sample invoice
                $invoice = $this->buildSampleInvoice($sample, $index + 1);

                // Generate XML, hash and sign
                $signed = $invoiceSigner->sign($invoice, $certificate);
                $invoice->setHash($signed->hash);

                // Submit for compliance check
                $response = $client->submitComplianceInvoice($signed->signedXml, $signed->hash, $invoice->getUuid());
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
                $this->reportFailure('    ERROR: ', $e);
                $failed++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Passed', (string) $passed);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        return $failed === 0;
    }

    /**
     * Where the device's chain stands, for its samples: its next counter and its previous hash, read under its
     * lock and let go at once, since a sample is never recorded in the chain.
     *
     * @return array{0: int, 1: string}
     */
    protected function chainPosition(): array
    {
        $chain = app(HashChainManager::class)->forDevice((string) $this->deviceId);

        return DB::transaction(fn (): array => [$chain->getNextIcv(), $chain->getPreviousInvoiceHash()]);
    }

    /**
     * The sample invoices the configured invoice types call for.
     *
     * @return list<array{type: string, subtype: string}>
     */
    protected function complianceSamples(): array
    {
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

        return $sampleInvoices;
    }

    /**
     * Build a sample invoice for compliance testing.
     *
     * @param  array{type: string, subtype: string}  $sample
     */
    protected function buildSampleInvoice(array $sample, int $number): InvoiceInterface
    {
        $position = $this->chainPosition();
        $builder = $this->sampleBuilder($sample);
        $builder->setInvoiceNumber("SME0000{$number}")
            ->setIcv($position[0])
            ->setPreviousInvoiceHash($position[1]);

        // Add buyer for standard invoices
        if ($sample['type'] !== 'simplified') {
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

        $builder->addLineItem(['name' => 'Test Product'] + ComplianceSample::LINE_ITEM)
            ->setVatGroups(ComplianceSample::VAT_GROUPS)
            ->setTotals(...ComplianceSample::TOTALS);

        return $builder->build();
    }

    /**
     * A builder for the sample's type and subtype; a credit or debit note refers to the original invoice with its reason.
     *
     * @param  array{type: string, subtype: string}  $sample
     */
    protected function sampleBuilder(array $sample): InvoiceBuilder
    {
        $isSimplified = $sample['type'] === 'simplified';

        if ($sample['subtype'] === 'credit_note') {
            $builder = InvoiceBuilder::creditNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Compliance test credit note');
        } elseif ($sample['subtype'] === 'debit_note') {
            $builder = InvoiceBuilder::debitNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Compliance test debit note');
        } else {
            $builder = $isSimplified
                ? InvoiceBuilder::simplified()
                : InvoiceBuilder::standard();
        }

        return $builder;
    }

    /**
     * Display validation errors from response.
     *
     * @param  array<string, mixed>  $response  ZATCA's JSON answer
     */
    protected function displayValidationErrors(array $response): void
    {
        $errors = $response['validationResults']['errorMessages'] ?? [];

        foreach ($errors as $error) {
            $this->line("      - {$error['message']} ({$error['code']})");
        }
    }
}
