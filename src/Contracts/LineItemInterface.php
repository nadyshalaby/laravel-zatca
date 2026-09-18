<?php

namespace Corecave\Zatca\Contracts;

use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;

interface LineItemInterface
{
    /**
     * Get the line item ID/index.
     */
    public function getId(): int;

    /**
     * Get the item name/description.
     */
    public function getName(): string;

    /**
     * Get the quantity.
     */
    public function getQuantity(): float;

    /**
     * Get the unit code (e.g., 'PCE', 'KGM').
     */
    public function getUnitCode(): string;

    /**
     * Get the unit price (before VAT).
     */
    public function getUnitPrice(): float;

    /**
     * Get the VAT category.
     */
    public function getVatCategory(): VatCategory;

    /**
     * Get the VAT rate (percentage).
     */
    public function getVatRate(): float;

    /**
     * Get the VAT exemption reason (if applicable).
     */
    public function getVatExemptionReason(): ?VatExemptionReason;

    /**
     * Get the line discount amount.
     */
    public function getDiscount(): float;

    /**
     * Get the line subtotal (quantity * unit price - discount).
     */
    public function getSubtotal(): float;

    /**
     * Get the line VAT amount.
     */
    public function getVatAmount(): float;

    /**
     * Get the line total (subtotal + VAT).
     */
    public function getTotal(): float;

    /**
     * Get additional item identifiers (e.g., SKU, barcode).
     */
    public function getIdentifiers(): array;

    /**
     * Convert to array.
     */
    public function toArray(): array;
}
