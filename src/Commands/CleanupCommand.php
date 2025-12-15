<?php

namespace Corecave\Zatca\Commands;

use Corecave\Zatca\Models\ZatcaCertificate;
use Corecave\Zatca\Models\ZatcaInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zatca:cleanup
                            {--certificates : Clean up all certificates from database}
                            {--compliance : Clean up only compliance certificates}
                            {--production : Clean up only production certificates}
                            {--csr : Clean up CSR and private key files}
                            {--invoices : Clean up all ZATCA invoices from database}
                            {--debug : Clean up debug files (XML dumps, etc.)}
                            {--all : Clean up everything}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up ZATCA certificates, CSR files, invoices, and debug files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('ZATCA Cleanup Utility');
        $this->newLine();

        // If no options specified, show help
        if (!$this->hasAnyOption()) {
            $this->warn('No cleanup options specified. Use one or more of the following:');
            $this->newLine();
            $this->line('  --certificates    Clean up all certificates from database');
            $this->line('  --compliance      Clean up only compliance certificates');
            $this->line('  --production      Clean up only production certificates');
            $this->line('  --csr             Clean up CSR and private key files');
            $this->line('  --invoices        Clean up all ZATCA invoices from database');
            $this->line('  --debug           Clean up debug files (XML dumps, etc.)');
            $this->line('  --all             Clean up everything');
            $this->line('  --force           Skip confirmation prompts');
            $this->newLine();
            $this->line('Example: php artisan zatca:cleanup --compliance --csr');

            return self::SUCCESS;
        }

        $all = $this->option('all');
        $force = $this->option('force');

        // Confirm if not forced
        if (!$force) {
            $items = $this->getCleanupItems($all);
            $this->warn('The following will be cleaned up:');
            foreach ($items as $item) {
                $this->line("  - {$item}");
            }
            $this->newLine();

            if (!$this->confirm('Are you sure you want to proceed?')) {
                $this->info('Cleanup cancelled.');
                return self::SUCCESS;
            }
        }

        $this->newLine();

        // Clean compliance certificates
        if ($all || $this->option('compliance') || $this->option('certificates')) {
            $this->cleanComplianceCertificates();
        }

        // Clean production certificates
        if ($all || $this->option('production') || $this->option('certificates')) {
            $this->cleanProductionCertificates();
        }

        // Clean CSR files
        if ($all || $this->option('csr')) {
            $this->cleanCsrFiles();
        }

        // Clean invoices
        if ($all || $this->option('invoices')) {
            $this->cleanInvoices();
        }

        // Clean debug files
        if ($all || $this->option('debug')) {
            $this->cleanDebugFiles();
        }

        $this->newLine();
        $this->info('Cleanup completed!');

        return self::SUCCESS;
    }

    /**
     * Check if any cleanup option is specified.
     */
    protected function hasAnyOption(): bool
    {
        return $this->option('certificates')
            || $this->option('compliance')
            || $this->option('production')
            || $this->option('csr')
            || $this->option('invoices')
            || $this->option('debug')
            || $this->option('all');
    }

    /**
     * Get list of items to be cleaned.
     */
    protected function getCleanupItems(bool $all): array
    {
        $items = [];

        if ($all || $this->option('compliance') || $this->option('certificates')) {
            $count = ZatcaCertificate::where('type', 'compliance')->count();
            $items[] = "Compliance certificates ({$count} found)";
        }

        if ($all || $this->option('production') || $this->option('certificates')) {
            $count = ZatcaCertificate::where('type', 'production')->count();
            $items[] = "Production certificates ({$count} found)";
        }

        if ($all || $this->option('csr')) {
            $items[] = 'CSR and private key files';
        }

        if ($all || $this->option('invoices')) {
            $count = ZatcaInvoice::count();
            $items[] = "ZATCA invoices ({$count} found)";
        }

        if ($all || $this->option('debug')) {
            $items[] = 'Debug files (XML dumps, QR codes, etc.)';
        }

        return $items;
    }

    /**
     * Clean compliance certificates from database.
     */
    protected function cleanComplianceCertificates(): void
    {
        $count = ZatcaCertificate::where('type', 'compliance')->count();

        if ($count === 0) {
            $this->line('  <fg=yellow>●</> No compliance certificates found.');
            return;
        }

        ZatcaCertificate::where('type', 'compliance')->delete();
        $this->line("  <fg=green>✓</> Deleted {$count} compliance certificate(s).");
    }

    /**
     * Clean production certificates from database.
     */
    protected function cleanProductionCertificates(): void
    {
        $count = ZatcaCertificate::where('type', 'production')->count();

        if ($count === 0) {
            $this->line('  <fg=yellow>●</> No production certificates found.');
            return;
        }

        ZatcaCertificate::where('type', 'production')->delete();
        $this->line("  <fg=green>✓</> Deleted {$count} production certificate(s).");
    }

    /**
     * Clean CSR and private key files.
     */
    protected function cleanCsrFiles(): void
    {
        $storagePath = storage_path('zatca');
        $files = [
            'csr.pem' => 'CSR file',
            'private_key.pem' => 'Private key file',
        ];

        $deleted = 0;

        foreach ($files as $filename => $label) {
            $path = $storagePath . '/' . $filename;

            if (File::exists($path)) {
                File::delete($path);
                $this->line("  <fg=green>✓</> Deleted {$label}: {$filename}");
                $deleted++;
            }
        }

        if ($deleted === 0) {
            $this->line('  <fg=yellow>●</> No CSR files found.');
        }
    }

    /**
     * Clean all ZATCA invoices from database.
     */
    protected function cleanInvoices(): void
    {
        $count = ZatcaInvoice::count();

        if ($count === 0) {
            $this->line('  <fg=yellow>●</> No ZATCA invoices found.');
            return;
        }

        ZatcaInvoice::truncate();
        $this->line("  <fg=green>✓</> Deleted {$count} ZATCA invoice(s).");

        // Also reset ICV counter if using database storage
        $this->resetIcvCounter();
    }

    /**
     * Reset ICV counter.
     */
    protected function resetIcvCounter(): void
    {
        $tableName = config('zatca.tables.invoices', 'zatca_invoices');

        // Reset auto-increment for ICV if needed
        try {
            \DB::statement("ALTER TABLE {$tableName} AUTO_INCREMENT = 1");
            $this->line('  <fg=green>✓</> Reset ICV counter.');
        } catch (\Exception $e) {
            // Ignore if not supported
        }
    }

    /**
     * Clean debug files.
     */
    protected function cleanDebugFiles(): void
    {
        $debugPath = storage_path(config('zatca.debug.path', 'zatca/debug'));

        if (!File::isDirectory($debugPath)) {
            $this->line('  <fg=yellow>●</> No debug directory found.');
            return;
        }

        $files = File::allFiles($debugPath);
        $count = count($files);

        if ($count === 0) {
            $this->line('  <fg=yellow>●</> No debug files found.');
            return;
        }

        File::cleanDirectory($debugPath);
        $this->line("  <fg=green>✓</> Deleted {$count} debug file(s).");
    }
}
