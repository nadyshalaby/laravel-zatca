<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Set the environment for ZATCA API calls.
    | Options: 'sandbox', 'simulation', 'production'
    |
    | - sandbox: For development and testing (no real submissions)
    | - simulation: ZATCA's simulation environment for testing
    | - production: Live FATOORA platform
    |
    */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    |
    | ZATCA API base URLs for different environments.
    |
    */
    'endpoints' => [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The ZATCA API version to use in requests.
    |
    */
    'api_version' => env('ZATCA_API_VERSION', 'V2'),

    /*
    |--------------------------------------------------------------------------
    | CSR Configuration
    |--------------------------------------------------------------------------
    |
    | Certificate Signing Request configuration for ZATCA onboarding.
    | These values are used to generate the CSR for obtaining CSID.
    |
    */
    'csr' => [
        // Country code (must be 'SA' for Saudi Arabia)
        'country' => env('ZATCA_CSR_COUNTRY', 'SA'),

        // Organization/Company legal name
        'organization' => env('ZATCA_CSR_ORGANIZATION'),

        // Organization unit (branch/department name)
        'organization_unit' => env('ZATCA_CSR_ORGANIZATION_UNIT'),

        // Common name for the certificate
        'common_name' => env('ZATCA_CSR_COMMON_NAME'),

        // Serial number format: 1-CompanyName|2-Version|3-UUID
        'serial_number' => env('ZATCA_CSR_SERIAL_NUMBER'),

        // VAT registration number (15 digits)
        'vat_number' => env('ZATCA_VAT_NUMBER'),

        // Invoice types supported:
        // '1000' = B2C only (simplified invoices)
        // '0100' = B2B only (standard invoices)
        // '1100' = Both B2B and B2C
        'invoice_types' => env('ZATCA_INVOICE_TYPES', '1100'),

        // EGS (E-invoice Generation Solution) unit details
        'egs_unit' => [
            'uuid' => env('ZATCA_EGS_UUID'),
            'custom_id' => env('ZATCA_EGS_CUSTOM_ID'),
        ],

        // Business location details
        'location' => [
            'building' => env('ZATCA_BUILDING'),
            'street' => env('ZATCA_STREET'),
            'city' => env('ZATCA_CITY'),
            'district' => env('ZATCA_DISTRICT'),
            'postal_code' => env('ZATCA_POSTAL_CODE'),
            'additional_number' => env('ZATCA_ADDITIONAL_NUMBER'),
        ],

        // Business category (e.g., 'Retail', 'Services', etc.)
        'business_category' => env('ZATCA_BUSINESS_CATEGORY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller Information
    |--------------------------------------------------------------------------
    |
    | Default seller information used in invoices.
    | This can be overridden per invoice if needed.
    |
    */
    'seller' => [
        // Seller's legal name (Arabic and/or English)
        'name' => env('ZATCA_SELLER_NAME'),

        // Seller's Arabic name (required for invoices)
        'name_ar' => env('ZATCA_SELLER_NAME_AR'),

        // VAT registration number (15 digits)
        'vat_number' => env('ZATCA_VAT_NUMBER'),

        // Commercial Registration number
        'registration_number' => env('ZATCA_REGISTRATION_NUMBER'),

        // Additional seller IDs
        'additional_ids' => [
            // Commercial Registration (CRN)
            'crn' => env('ZATCA_SELLER_CRN'),

            // MOMRA License
            'momra' => env('ZATCA_SELLER_MOMRA'),

            // MLSD License
            'mlsd' => env('ZATCA_SELLER_MLSD'),

            // SAGIA License
            'sagia' => env('ZATCA_SELLER_SAGIA'),

            // Other ID
            'other' => env('ZATCA_SELLER_OTHER_ID'),
        ],

        // Seller address
        'address' => [
            'street' => env('ZATCA_SELLER_STREET'),
            'building' => env('ZATCA_SELLER_BUILDING'),
            'plot' => env('ZATCA_SELLER_PLOT'),
            'city' => env('ZATCA_SELLER_CITY'),
            'city_subdivision' => env('ZATCA_SELLER_CITY_SUBDIVISION'),
            'district' => env('ZATCA_SELLER_DISTRICT'),
            'postal_code' => env('ZATCA_SELLER_POSTAL_CODE'),
            'additional_number' => env('ZATCA_SELLER_ADDITIONAL_NUMBER'),
            'country' => 'SA',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Storage
    |--------------------------------------------------------------------------
    |
    | Configuration for storing ZATCA certificates and keys.
    |
    */
    'certificates' => [
        // Storage driver: 'database' or 'file'
        'storage' => env('ZATCA_CERT_STORAGE', 'database'),

        // File storage path (used when storage is 'file')
        'path' => storage_path('zatca/certificates'),

        // Encryption key for sensitive data (uses app key if not set)
        'encryption_key' => env('ZATCA_CERT_ENCRYPTION_KEY'),

        // Auto-renew certificates before expiry (days before expiry)
        'auto_renew_days' => env('ZATCA_CERT_AUTO_RENEW_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    |
    | Default invoice configuration settings.
    |
    */
    'invoice' => [
        // Default currency code
        'currency' => env('ZATCA_INVOICE_CURRENCY', 'SAR'),

        // Standard VAT rate in Saudi Arabia
        'vat_rate' => env('ZATCA_VAT_RATE', 15.00),

        // Counter storage for ICV (Invoice Counter Value)
        'counter_storage' => env('ZATCA_COUNTER_STORAGE', 'database'),

        // Decimal precision for amounts
        'decimal_precision' => 2,

        // Date format for invoices
        'date_format' => 'Y-m-d',

        // Time format for invoices
        'time_format' => 'H:i:s',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Request Settings
    |--------------------------------------------------------------------------
    |
    | HTTP client configuration for ZATCA API requests.
    |
    */
    'http' => [
        // Request timeout in seconds
        'timeout' => env('ZATCA_HTTP_TIMEOUT', 30),

        // Connection timeout in seconds
        'connect_timeout' => env('ZATCA_HTTP_CONNECT_TIMEOUT', 10),

        // Number of retries for failed requests
        'retries' => env('ZATCA_HTTP_RETRIES', 3),

        // Delay between retries in milliseconds
        'retry_delay' => env('ZATCA_HTTP_RETRY_DELAY', 1000),

        // Verify SSL certificates
        'verify_ssl' => env('ZATCA_HTTP_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Logging configuration for ZATCA operations.
    |
    */
    'logging' => [
        // Enable/disable logging
        'enabled' => env('ZATCA_LOGGING_ENABLED', true),

        // Log channel to use
        'channel' => env('ZATCA_LOG_CHANNEL', 'stack'),

        // Log level for ZATCA operations
        'level' => env('ZATCA_LOG_LEVEL', 'info'),

        // Log API requests/responses (may contain sensitive data)
        'log_api_calls' => env('ZATCA_LOG_API_CALLS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Settings
    |--------------------------------------------------------------------------
    |
    | Debug configuration for ZATCA invoice processing.
    | When enabled, generated XML and QR codes are dumped to files.
    |
    */
    'debug' => [
        // Enable/disable debug file dumping
        'enabled' => env('ZATCA_DEBUG_ENABLED', false),

        // Directory to store debug files (relative to storage_path)
        'path' => env('ZATCA_DEBUG_PATH', 'zatca/debug'),

        // Dump unsigned XML before signing
        'dump_unsigned_xml' => env('ZATCA_DEBUG_DUMP_UNSIGNED', true),

        // Dump signed XML after signing
        'dump_signed_xml' => env('ZATCA_DEBUG_DUMP_SIGNED', true),

        // Dump QR code data
        'dump_qr' => env('ZATCA_DEBUG_DUMP_QR', true),

        // Dump invoice hash
        'dump_hash' => env('ZATCA_DEBUG_DUMP_HASH', true),

        // Include timestamp in filenames
        'timestamp_files' => env('ZATCA_DEBUG_TIMESTAMP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Database table names for ZATCA data storage.
    |
    */
    'tables' => [
        'certificates' => 'zatca_certificates',
        'invoices' => 'zatca_invoices',
    ],
];
