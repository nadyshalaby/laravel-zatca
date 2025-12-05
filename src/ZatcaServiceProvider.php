<?php

namespace Corecave\Zatca;

use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Client\ZatcaClient;
use Corecave\Zatca\Commands\ComplianceCommand;
use Corecave\Zatca\Commands\GenerateCsrCommand;
use Corecave\Zatca\Commands\ProductionCsidCommand;
use Corecave\Zatca\Commands\RenewCsidCommand;
use Corecave\Zatca\Contracts\ApiClientInterface;
use Corecave\Zatca\Hash\HashChainManager;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Corecave\Zatca\Xml\XmlValidator;
use Illuminate\Support\ServiceProvider;

class ZatcaServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/zatca.php',
            'zatca'
        );

        // Register the main Zatca manager
        $this->app->singleton('zatca', function ($app) {
            return new ZatcaManager($app);
        });

        // Register API client
        $this->app->singleton(ApiClientInterface::class, function ($app) {
            return new ZatcaClient(
                config('zatca.environment', 'sandbox')
            );
        });

        // Register CSR generator
        $this->app->singleton(CsrGenerator::class, function ($app) {
            return new CsrGenerator(config('zatca.csr', []));
        });

        // Register certificate manager
        $this->app->singleton(CertificateManager::class, function ($app) {
            return new CertificateManager(
                config('zatca.certificates', [])
            );
        });

        // Register UBL generator
        $this->app->singleton(UblGenerator::class, function ($app) {
            return new UblGenerator();
        });

        // Register XML signer
        $this->app->singleton(XmlSigner::class, function ($app) {
            return new XmlSigner();
        });

        // Register XML validator
        $this->app->singleton(XmlValidator::class, function ($app) {
            return new XmlValidator();
        });

        // Register QR generator
        $this->app->singleton(QrGenerator::class, function ($app) {
            return new QrGenerator();
        });

        // Register hash chain manager
        $this->app->singleton(HashChainManager::class, function ($app) {
            return new HashChainManager();
        });

        // Register invoice builder
        $this->app->bind(InvoiceBuilder::class, function ($app) {
            return new InvoiceBuilder(
                config('zatca.seller', []),
                config('zatca.invoice', [])
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/zatca.php' => config_path('zatca.php'),
        ], 'zatca-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'zatca-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCsrCommand::class,
                ComplianceCommand::class,
                ProductionCsidCommand::class,
                RenewCsidCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            'zatca',
            ApiClientInterface::class,
            CsrGenerator::class,
            CertificateManager::class,
            UblGenerator::class,
            XmlSigner::class,
            XmlValidator::class,
            QrGenerator::class,
            HashChainManager::class,
            InvoiceBuilder::class,
        ];
    }
}
