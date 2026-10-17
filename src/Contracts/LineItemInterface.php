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
     * Get the quantity (BT-129), as priced: whole units with up to six decimals.
     */
    public function getQuantity(): string;

    /**
     * Get the unit code (e.g., 'PCE', 'KGM').
     */
    public function getUnitCode(): string;

    /**
     * Get the unit price before VAT (BT-146), as priced.
     */
    public function getUnitPrice(): string;

    /**
     * Get the VAT category.
     */
    public function getVatCategory(): VatCategory;

    /**
     * Get the VAT rate (BT-152), a percentage with two decimals.
     */
    public function getVatRate(): string;

    /**
     * Get the VAT exemption reason (if applicable).
     */
    public function getVatExemptionReason(): ?VatExemptionReason;

    /**
     * Get the line amount, the quantity times the unit price, as priced.
     */
    public function getLineAmount(): string;

    /**
     * Get the line discount (BT-136), as priced.
     */
    public function getDiscount(): string;

    /**
     * Whether the line carries a discount.
     */
    public function hasDiscount(): bool;

    /**
     * Get the line net (BT-131), the line amount less the discount, as priced.
     */
    public function getSubtotal(): string;

    /**
     * Get the line VAT (KSA-11), rounded once from the net, as priced.
     */
    public function getVatAmount(): string;

    /**
     * Get the line amount with VAT (KSA-12), the net plus the line VAT, as priced.
     */
    public function getTotal(): string;

    /**
     * Get additional item identifiers (e.g., SKU, barcode).
     *
     * @return array<string, mixed>
     */
    public function getIdentifiers(): array;

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
