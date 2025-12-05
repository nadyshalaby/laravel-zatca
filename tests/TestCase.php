<?php

namespace Corecave\Zatca\Tests;

use Corecave\Zatca\ZatcaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ZatcaServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up test configuration
        $app['config']->set('zatca.environment', 'sandbox');
        $app['config']->set('zatca.seller', [
            'name' => 'Test Company LLC',
            'name_ar' => 'شركة الاختبار',
            'vat_number' => '300000000000003',
            'registration_number' => '1234567890',
            'address' => [
                'street' => 'Main Street',
                'building' => '1234',
                'city' => 'Riyadh',
                'district' => 'Al Olaya',
                'postal_code' => '12345',
                'country' => 'SA',
            ],
        ]);
        $app['config']->set('zatca.invoice', [
            'currency' => 'SAR',
            'vat_rate' => 15.00,
        ]);

        // Use SQLite in-memory database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
