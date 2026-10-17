<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Invoice\ComplianceChecks;
use Corecave\Zatca\Results\ComplianceCheck;
use Illuminate\Console\Command;

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
    public function handle(CertificateManager $certManager, ZatcaClient $client, ComplianceChecks $checks): int
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

        return $this->completeCompliance($client, $checks, $complianceCert);
    }

    /**
     * Run the compliance checks unless they are skipped, and show the next steps.
     */
    protected function completeCompliance(ZatcaClient $client, ComplianceChecks $checks, Certificate $complianceCert): int
    {
        $this->info('Compliance certificate stored.');
        $this->newLine();

        // Step 2: Run compliance checks
        if (! $this->option('skip-checks')) {
            $this->info('Step 2: Running compliance checks...');

            $client->setCertificate($complianceCert);

            $checksResult = $this->runComplianceChecks($client, $checks, $complianceCert);

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
    protected function runComplianceChecks(ZatcaClient $client, ComplianceChecks $checks, Certificate $certificate): bool
    {
        $passed = 0;
        $failed = 0;
        $results = $checks->forDevice((string) $this->deviceId)->run($client, $certificate, config('zatca.csr.invoice_types', '1100'));

        foreach ($results as $check) {
            $this->line("  Submitting {$check->sample['type']} {$check->sample['subtype']}...");
            $this->reportCheck($check);

            if ($check->isPassed()) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Passed', (string) $passed);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        return $failed === 0;
    }

    /**
     * One sample's answer: passed, failed with ZATCA's status and messages, or never answered.
     */
    protected function reportCheck(ComplianceCheck $check): void
    {
        if ($check->failure !== null) {
            $this->reportFailure('    ERROR: ', $check->failure);
        } elseif ($check->isPassed()) {
            $this->info('    PASS');
        } else {
            $this->error("    FAIL: {$check->status}");
            $this->displayValidationErrors(['validationResults' => ['errorMessages' => $check->errors]]);
        }
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
