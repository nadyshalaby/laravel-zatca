<?php

namespace Corecave\Zatca\Enums;

/**
 * Invoice Sub-Type codes as per ZATCA specification.
 *
 * The sub-type is a 7-digit bitmask (TNNPNESB format) that indicates:
 * - Position 1: Third-party transaction
 * - Position 2-3: Nominal transaction
 * - Position 4: Export
 * - Position 5: Summary
 * - Position 6: Self-billed
 * - Position 7: 1=Standard (B2B), 2=Simplified (B2C)
 *
 * Note: The invoice type (Invoice/Credit Note/Debit Note) is determined
 * by the InvoiceType enum (388/381/383), not by the sub-type.
 */
enum InvoiceSubType: string
{
    // Standard (B2B) sub-types
    case STANDARD = '0100000';

    // Simplified (B2C) sub-types
    case SIMPLIFIED = '0200000';

    // Third-party Standard Invoice
    case THIRD_PARTY_STANDARD = '0100100';

    // Third-party Simplified Invoice
    case THIRD_PARTY_SIMPLIFIED = '0200100';

    // Nominal Standard Invoice
    case NOMINAL_STANDARD = '0101000';

    // Nominal Simplified Invoice
    case NOMINAL_SIMPLIFIED = '0201000';

    // Export Invoice (Standard only)
    case EXPORT = '0100010';

    // Summary Invoice (Standard only)
    case SUMMARY = '0100001';

    // Self-billed Invoice
    case SELF_BILLED = '0110000';

    /**
     * Check if this is a simplified (B2C) invoice type.
     */
    public function isSimplified(): bool
    {
        return in_array($this, [
            self::SIMPLIFIED,
            self::THIRD_PARTY_SIMPLIFIED,
            self::NOMINAL_SIMPLIFIED,
        ]);
    }

    /**
     * Check if this is a standard (B2B/B2G) invoice type.
     */
    public function isStandard(): bool
    {
        return !$this->isSimplified();
    }

    /**
     * Get the label for this sub-type.
     */
    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard (B2B)',
            self::SIMPLIFIED => 'Simplified (B2C)',
            self::THIRD_PARTY_STANDARD => 'Third-party Standard',
            self::THIRD_PARTY_SIMPLIFIED => 'Third-party Simplified',
            self::NOMINAL_STANDARD => 'Nominal Standard',
            self::NOMINAL_SIMPLIFIED => 'Nominal Simplified',
            self::EXPORT => 'Export',
            self::SUMMARY => 'Summary',
            self::SELF_BILLED => 'Self-billed',
        };
    }

    /**
     * Get the standard sub-type for regular invoices.
     */
    public static function standard(): self
    {
        return self::STANDARD;
    }

    /**
     * Get the simplified sub-type for B2C invoices.
     */
    public static function simplified(): self
    {
        return self::SIMPLIFIED;
    }
}
