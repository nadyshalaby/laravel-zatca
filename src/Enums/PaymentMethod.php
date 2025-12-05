<?php

namespace Corecave\Zatca\Enums;

/**
 * Payment Method codes as per UN/CEFACT 4461.
 */
enum PaymentMethod: string
{
    case CASH = '10';
    case CREDIT = '30';
    case BANK_CARD = '48';
    case DIRECT_DEBIT = '49';
    case BANK_TRANSFER = '42';
    case UNKNOWN = '1';

    /**
     * Get the label for this payment method.
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::CREDIT => 'Credit',
            self::BANK_CARD => 'Bank Card',
            self::DIRECT_DEBIT => 'Direct Debit',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::UNKNOWN => 'Unknown',
        };
    }
}
