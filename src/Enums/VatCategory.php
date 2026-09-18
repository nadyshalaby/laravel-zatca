<?php

namespace Corecave\Zatca\Enums;

/**
 * VAT Category codes as per UN/CEFACT 5305.
 */
enum VatCategory: string
{
    // Standard rate (15% in KSA)
    case STANDARD = 'S';

    // Zero rated
    case ZERO_RATED = 'Z';

    // Exempt from VAT
    case EXEMPT = 'E';

    // Out of scope (not subject to VAT)
    case OUT_OF_SCOPE = 'O';

    /**
     * Get the default VAT rate for this category.
     */
    public function defaultRate(): float
    {
        return match ($this) {
            self::STANDARD => 15.00,
            self::ZERO_RATED => 0.00,
            self::EXEMPT => 0.00,
            self::OUT_OF_SCOPE => 0.00,
        };
    }

    /**
     * Get the label for this category.
     */
    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard Rate',
            self::ZERO_RATED => 'Zero Rated',
            self::EXEMPT => 'VAT Exempt',
            self::OUT_OF_SCOPE => 'Not Subject to VAT',
        };
    }

    /**
     * Check if this category requires a VAT rate.
     */
    public function requiresRate(): bool
    {
        return $this !== self::OUT_OF_SCOPE;
    }

    /**
     * Check if this is a taxable category (has actual VAT).
     */
    public function isTaxable(): bool
    {
        return $this === self::STANDARD;
    }
}
