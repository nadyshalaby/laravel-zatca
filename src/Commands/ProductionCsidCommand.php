<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Client\ZatcaClient;
use Illuminate\Console\Command;

class ProductionCsidCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:production-csid
                            {--request-id= : Compliance request ID (if not stored)}';

    /**
     * The console command description.
     */
    protected $description = 'Request a Production CSID after completing compliance checks';

    /**
     * Execute the console command.
     */
    public function handle(CertificateManager $certManager, ZatcaClient $client): int
    {
        $this->info('Requesting Production CSID...');
        $this->newLine();

        // Get compliance certificate
        $complianceCert = $certManager->getActive('compliance');

        if (! $complianceCert) {
            $this->error('No active compliance certificate found.');
            $this->line('Please run: php artisan zatca:compliance first');

            return self::FAILURE;
        }

        // Get request ID
        $requestId = $this->option('request-id') ?? $complianceCert->getRequestId();

        if (empty($requestId)) {
            $requestId = $this->ask('Enter the Compliance Request ID');
        }

        $this->components->twoColumnDetail('Compliance Request ID', $requestId);
        $this->newLine();

        try {
            $client->setCertificate($complianceCert);
            $response = $client->requestProductionCsid($requestId);

            $this->info('Production CSID received!');
            $this->newLine();

            // Store production certificate
            $productionCert = Certificate::fromApiResponse(
                $response,
                $complianceCert->getPrivateKey(),
                'production'
            );

            $certManager->store($productionCert);

            // Display certificate info
            $this->components->twoColumnDetail('Certificate Type', 'Production');
            $this->components->twoColumnDetail('Request ID', $response['requestID'] ?? 'N/A');
            $this->components->twoColumnDetail('Token Type', $response['tokenType'] ?? 'N/A');

            if ($productionCert->getExpiresAt()) {
                $this->components->twoColumnDetail('Expires', $productionCert->getExpiresAt()->format('Y-m-d H:i:s'));
                $this->components->twoColumnDetail('Days Until Expiry', (string) $productionCert->getExpiresAt()->diffInDays(now()));
            }

            $this->newLine();
            $this->info('Production certificate stored and activated!');
            $this->newLine();

            $this->info('You can now start issuing invoices:');
            $this->line('  - Use Zatca::report() for simplified (B2C) invoices');
            $this->line('  - Use Zatca::clear() for standard (B2B) invoices');
            $this->line('  - Or use Zatca::process() to automatically determine the type');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to get Production CSID: '.$e->getMessage());

            if (str_contains($e->getMessage(), 'compliance')) {
                $this->warn('Make sure you have completed all compliance checks.');
            }

            return self::FAILURE;
        }
    }
}
