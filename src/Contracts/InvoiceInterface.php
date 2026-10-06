<?php

namespace Corecave\Zatca\Contracts;

use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;
use Corecave\Zatca\Enums\PaymentMethod;
use Corecave\Zatca\Invoice\DocumentTotals;
use Corecave\Zatca\Invoice\VatGroup;
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
     *
     * @return array<string, mixed>
     */
    public function getSeller(): array;

    /**
     * Get the seller's VAT registration number.
     */
    public function getSellerVatNumber(): string;

    /**
     * Get buyer information.
     *
     * @return array<string, mixed>|null
     */
    public function getBuyer(): ?array;

    /**
     * Get line items.
     *
     * @return array<int, LineItemInterface>
     */
    public function getLineItems(): array;

    /**
     * Get the sum of the line nets (BT-106, also BT-109), as priced.
     */
    public function getSubtotal(): string;

    /**
     * Get the total VAT (BT-110), the sum of the groups' tax, as priced.
     */
    public function getTotalVat(): string;

    /**
     * Get the total with VAT (BT-112, also the payable amount BT-115), as priced.
     */
    public function getTotalWithVat(): string;

    /**
     * Get the three totals, as priced.
     */
    public function getTotals(): DocumentTotals;

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
     *
     * @return array<int, string>
     */
    public function getNotes(): array;

    /**
     * Get the VAT groups (BT-116 to BT-121), as priced: one per category and rate.
     *
     * @return list<VatGroup>
     */
    public function getVatBreakdown(): array;

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
