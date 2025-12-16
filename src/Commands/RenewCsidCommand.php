<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Contracts\ApiClientInterface;
use Illuminate\Console\Command;

class RenewCsidCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:renew-csid
                            {--otp= : OTP from FATOORA portal}
                            {--force : Force renewal even if certificate is not expiring}';

    /**
     * The console command description.
     */
    protected $description = 'Renew the Production CSID before expiry';

    /**
     * Execute the console command.
     */
    public function handle(
        CertificateManager $certManager,
        CsrGenerator $csrGenerator,
        ApiClientInterface $client
    ): int {
        $this->info('ZATCA Production CSID Renewal');
        $this->newLine();

        // Get current production certificate
        $currentCert = $certManager->getActive('production');

        if (! $currentCert) {
            $this->error('No active production certificate found.');
            $this->line('Please complete the onboarding process first.');

            return self::FAILURE;
        }

        // Check expiry
        $daysUntilExpiry = $currentCert->getExpiresAt()?->diffInDays(now()) ?? 0;

        $this->components->twoColumnDetail('Current Certificate Expires', $currentCert->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Unknown');
        $this->components->twoColumnDetail('Days Until Expiry', (string) $daysUntilExpiry);
        $this->newLine();

        // Warn if not expiring soon
        if ($daysUntilExpiry > 30 && ! $this->option('force')) {
            $this->warn('Certificate is not expiring soon.');

            if (! $this->confirm('Do you still want to renew?', false)) {
                return self::SUCCESS;
            }
        }

        // Get OTP
        $otp = $this->option('otp');
        if (empty($otp)) {
            $otp = $this->ask('Enter OTP from FATOORA portal');
        }

        // Generate new CSR
        $this->info('Generating new CSR...');

        try {
            $csrData = $csrGenerator->generate();
        } catch (\Exception $e) {
            $this->error('Failed to generate CSR: '.$e->getMessage());

            return self::FAILURE;
        }

        // Request renewal
        $this->info('Requesting CSID renewal...');

        try {
            $client->setCertificate($currentCert);
            $response = $client->renewProductionCsid($csrData['csr'], $otp);

            $this->info('CSID renewed successfully!');
            $this->newLine();

            // Store new certificate
            $newCert = Certificate::fromApiResponse(
                $response,
                $csrData['private_key'],
                'production'
            );

            $certManager->store($newCert);

            // Display new certificate info
            $this->components->twoColumnDetail('New Certificate Expires', $newCert->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Unknown');
            $this->components->twoColumnDetail('Request ID', $response['requestID'] ?? 'N/A');

            $this->newLine();
            $this->info('New production certificate stored and activated!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to renew CSID: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
