<?php

namespace Corecave\Zatca\Exceptions;

class InvoiceException extends ZatcaException
{
    /**
     * Missing required field.
     */
    public static function missingField(string $field): static
    {
        return new static("Missing required invoice field: {$field}");
    }

    /**
     * Invalid field value.
     */
    public static function invalidField(string $field, string $reason): static
    {
        return new static("Invalid value for invoice field '{$field}': {$reason}");
    }

    /**
     * No line items.
     */
    public static function noLineItems(): static
    {
        return new static('Invoice must have at least one line item.');
    }

    /**
     * Invalid line item.
     */
    public static function invalidLineItem(int $index, string $reason): static
    {
        return new static("Invalid line item at index {$index}: {$reason}");
    }

    /**
     * Invalid tax calculation.
     */
    public static function invalidTaxCalculation(string $reason): static
    {
        return new static("Invalid tax calculation: {$reason}");
    }

    /**
     * Credit/debit note requires reference.
     */
    public static function missingOriginalInvoice(): static
    {
        return new static('Credit/debit notes must reference an original invoice.');
    }

    /**
     * Buyer required for standard invoice.
     */
    public static function buyerRequired(): static
    {
        return new static('Buyer information is required for standard (B2B) invoices.');
    }

    /**
     * Hash chain broken.
     */
    public static function hashChainBroken(): static
    {
        return new static('Invoice hash chain verification failed. Previous invoice hash mismatch.');
    }
}
