<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsidRenewal;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Contracts\CertificateInterface;
use Illuminate\Console\Command;

class RenewCsidCommand extends Command
{
    use AsksForOtp, ChoosesDevice, ReportsFailures;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zatca:renew-csid
                            {--device= : The ZATCA device whose certificate is renewed}
                            {--otp= : OTP from FATOORA portal}
                            {--force : Force renewal even if certificate is not expiring}';

    /**
     * The console command description.
     */
    protected $description = 'Renew the Production CSID before expiry';

    /**
     * Execute the console command.
     */
    public function handle(CertificateManager $certManager, CsrGenerator $csrGenerator, ZatcaClient $client): int
    {
        $this->info('ZATCA Production CSID Renewal');
        $this->newLine();
        $certManager = $this->deviceCertificates($certManager);

        if ($certManager === null) {
            return self::FAILURE;
        }

        $currentCert = $certManager->getActive('production');

        if (! $currentCert) {
            $this->error('No active production certificate found.');
            $this->line('Please complete the onboarding process first.');

            return self::FAILURE;
        }

        if (! $this->confirmRenewal($currentCert)) {
            return self::SUCCESS;
        }

        $otp = $this->otp();

        // Generate new CSR
        $this->info('Generating new CSR...');

        try {
            $csrData = $csrGenerator->generate();
        } catch (\Exception $e) {
            $this->reportFailure('Failed to generate CSR: ', $e);

            return self::FAILURE;
        }

        return $this->requestRenewal($certManager, $client, new CsidRenewal($currentCert, $csrData, $otp));
    }

    /**
     * Show when the current certificate expires, and whether to renew it: one that is not expiring soon is renewed only when forced or confirmed.
     */
    protected function confirmRenewal(CertificateInterface $currentCert): bool
    {
        // Check expiry
        $expiresAt = $currentCert->getExpiresAt();
        $daysUntilExpiry = $expiresAt === null ? 0 : now()->diffInDays($expiresAt);

        $this->components->twoColumnDetail('Current Certificate Expires', $currentCert->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Unknown');
        $this->components->twoColumnDetail('Days Until Expiry', (string) $daysUntilExpiry);
        $this->newLine();

        // Warn if not expiring soon
        if ($daysUntilExpiry > 30 && ! $this->option('force')) {
            $this->warn('Certificate is not expiring soon.');

            return $this->confirm('Do you still want to renew?', false);
        }

        return true;
    }

    /**
     * Request the renewal from ZATCA, and store and show the new certificate.
     */
    protected function requestRenewal(CertificateManager $certManager, ZatcaClient $client, CsidRenewal $renewal): int
    {
        // Request renewal
        $this->info('Requesting CSID renewal...');

        try {
            $client->setCertificate($renewal->certificate);
            $response = $client->renewProductionCsid($renewal->csrData['csr'], $renewal->otp);

            $this->info('CSID renewed successfully!');
            $this->newLine();

            // Store new certificate
            $newCert = Certificate::fromApiResponse(
                $response,
                $renewal->csrData['private_key'],
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
            $this->reportFailure('Failed to renew CSID: ', $e);

            return self::FAILURE;
        }
    }
}
