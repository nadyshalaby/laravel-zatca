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
     * A line whose discount is more than its line amount, which would leave it a negative amount.
     */
    public static function discountExceedsLine(int $index, string $discount, string $lineAmount): static
    {
        return static::invalidLineItem($index, "its discount of {$discount} is more than its line amount, {$lineAmount}");
    }

    /**
     * An amount handed over in any other form than whole units, a point and two decimals.
     */
    public static function invalidAmount(string $amount): static
    {
        return new static("Invalid amount '{$amount}': an amount is whole units, a point and two decimals, as it was priced");
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

    /**
     * Hash chain lock asked for outside a transaction, where it would be released at once.
     */
    public static function hashChainLockOutsideTransaction(): static
    {
        return new static('The invoice hash chain lock must be taken inside a database transaction.');
    }

    /**
     * Hash chain lock asked for on a connection with no current schema, so there is nothing to key it on.
     */
    public static function hashChainLockWithoutSchema(): static
    {
        return new static('The invoice hash chain lock needs a current database schema, and the connection has none.');
    }

    /**
     * A device's chain read or written before the device was chosen, so there is no chain to use.
     */
    public static function noDevice(): static
    {
        return new static('The invoice hash chain belongs to a device: choose it with forDevice() first.');
    }
}
