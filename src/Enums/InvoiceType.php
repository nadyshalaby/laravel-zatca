<?php

namespace Corecave\Zatca\Enums;

/**
 * Invoice Type codes as per UN/CEFACT 1001.
 *
 * Note: Both standard and simplified invoices use code 388.
 * The differentiation is done via the sub-type (InvoiceSubType).
 */
enum InvoiceType: string
{
    case INVOICE = '388';      // Both standard and simplified invoices
    case CREDIT_NOTE = '381';
    case DEBIT_NOTE = '383';

    /**
     * Get the invoice type code name.
     */
    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Tax Invoice',
            self::CREDIT_NOTE => 'Credit Note',
            self::DEBIT_NOTE => 'Debit Note',
        };
    }

    /**
     * Check if this is an invoice (not a credit/debit note).
     */
    public function isInvoice(): bool
    {
        return $this === self::INVOICE;
    }

    /**
     * Check if this is a credit note.
     */
    public function isCreditNote(): bool
    {
        return $this === self::CREDIT_NOTE;
    }

    /**
     * Check if this is a debit note.
     */
    public function isDebitNote(): bool
    {
        return $this === self::DEBIT_NOTE;
    }
}
