<?php

namespace Corecave\Zatca\Contracts;

use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;
use Corecave\Zatca\Enums\PaymentMethod;
use DateTimeInterface;

interface InvoiceInterface
{
    /**
     * Get the invoice UUID.
     */
    public function getUuid(): string;

    /**
     * Get the invoice number/ID.
     */
    public function getInvoiceNumber(): string;

    /**
     * Get the invoice type.
     */
    public function getType(): InvoiceType;

    /**
     * Get the invoice sub-type.
     */
    public function getSubType(): InvoiceSubType;

    /**
     * Get the invoice type code (e.g., '388').
     */
    public function getTypeCode(): string;

    /**
     * Get the invoice sub-type code (e.g., '0100000').
     */
    public function getSubTypeCode(): string;

    /**
     * Get the issue date.
     */
    public function getIssueDate(): DateTimeInterface;

    /**
     * Get the supply date (if different from issue date).
     */
    public function getSupplyDate(): ?DateTimeInterface;

    /**
     * Get seller information.
     */
    public function getSeller(): array;

    /**
     * Get the seller's VAT registration number.
     */
    public function getSellerVatNumber(): string;

    /**
     * Get buyer information.
     */
    public function getBuyer(): ?array;

    /**
     * Get line items.
     */
    public function getLineItems(): array;

    /**
     * Get the subtotal (before VAT).
     */
    public function getSubtotal(): float;

    /**
     * Get total VAT amount.
     */
    public function getTotalVat(): float;

    /**
     * Get total amount with VAT.
     */
    public function getTotalWithVat(): float;

    /**
     * Get total discount amount.
     */
    public function getTotalDiscount(): float;

    /**
     * Get the currency code.
     */
    public function getCurrency(): string;

    /**
     * Get the Invoice Counter Value (ICV).
     */
    public function getIcv(): int;

    /**
     * Get the Previous Invoice Hash (PIH).
     */
    public function getPreviousInvoiceHash(): string;

    /**
     * Get the invoice hash.
     */
    public function getHash(): ?string;

    /**
     * Set the invoice hash.
     */
    public function setHash(string $hash): void;

    /**
     * Get payment method code.
     */
    public function getPaymentMethod(): ?string;

    /**
     * Get payment method enum.
     */
    public function getPaymentMethodEnum(): ?PaymentMethod;

    /**
     * Get payment terms/notes.
     */
    public function getPaymentTerms(): ?string;

    /**
     * Check if this is a simplified (B2C) invoice.
     */
    public function isSimplified(): bool;

    /**
     * Check if this is a standard (B2B) invoice.
     */
    public function isStandard(): bool;

    /**
     * Check if this is a credit note.
     */
    public function isCreditNote(): bool;

    /**
     * Check if this is a debit note.
     */
    public function isDebitNote(): bool;

    /**
     * Get original invoice reference (for credit/debit notes).
     */
    public function getOriginalInvoiceReference(): ?string;

    /**
     * Get the reason for the credit/debit note.
     */
    public function getInstructionNote(): ?string;

    /**
     * Get additional notes.
     */
    public function getNotes(): array;

    /**
     * Get VAT breakdown by category.
     */
    public function getVatBreakdown(): array;

    /**
     * Convert to array.
     */
    public function toArray(): array;
}
