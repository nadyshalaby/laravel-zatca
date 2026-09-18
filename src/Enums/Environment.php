<?php

namespace Corecave\Zatca\Enums;

enum Environment: string
{
    case SANDBOX = 'sandbox';
    case SIMULATION = 'simulation';
    case PRODUCTION = 'production';

    /**
     * Get the API base URL for this environment.
     */
    public function baseUrl(): string
    {
        return match ($this) {
            self::SANDBOX => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            self::SIMULATION => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            self::PRODUCTION => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
        };
    }

    /**
     * Check if this is a production environment.
     */
    public function isProduction(): bool
    {
        return $this === self::PRODUCTION;
    }

    /**
     * Check if this is a testing environment (sandbox or simulation).
     */
    public function isTesting(): bool
    {
        return ! $this->isProduction();
    }

    /**
     * Get the label for this environment.
     */
    public function label(): string
    {
        return match ($this) {
            self::SANDBOX => 'Sandbox (Development)',
            self::SIMULATION => 'Simulation (Testing)',
            self::PRODUCTION => 'Production (Live)',
        };
    }
}
