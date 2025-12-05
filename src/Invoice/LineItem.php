<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Contracts\LineItemInterface;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;

class LineItem implements LineItemInterface
{
    protected int $id;

    protected string $name;

    protected float $quantity;

    protected string $unitCode;

    protected float $unitPrice;

    protected VatCategory $vatCategory;

    protected float $vatRate;

    protected ?VatExemptionReason $vatExemptionReason;

    protected float $discount;

    protected array $identifiers;

    public function __construct(
        int $id,
        string $name,
        float $quantity,
        float $unitPrice,
        VatCategory $vatCategory = VatCategory::STANDARD,
        ?float $vatRate = null,
        string $unitCode = 'PCE',
        float $discount = 0.0,
        ?VatExemptionReason $vatExemptionReason = null,
        array $identifiers = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
        $this->vatCategory = $vatCategory;
        $this->vatRate = $vatRate ?? $vatCategory->defaultRate();
        $this->unitCode = $unitCode;
        $this->discount = $discount;
        $this->vatExemptionReason = $vatExemptionReason;
        $this->identifiers = $identifiers;
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data, int $id = 1): self
    {
        $vatCategory = $data['vat_category'] ?? VatCategory::STANDARD;

        if (is_string($vatCategory)) {
            $vatCategory = VatCategory::from($vatCategory);
        }

        $vatExemptionReason = $data['vat_exemption_reason'] ?? null;
        if (is_string($vatExemptionReason)) {
            $vatExemptionReason = VatExemptionReason::from($vatExemptionReason);
        }

        return new self(
            id: $data['id'] ?? $id,
            name: $data['name'],
            quantity: (float) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
            vatCategory: $vatCategory,
            vatRate: $data['vat_rate'] ?? null,
            unitCode: $data['unit_code'] ?? 'PCE',
            discount: (float) ($data['discount'] ?? 0),
            vatExemptionReason: $vatExemptionReason,
            identifiers: $data['identifiers'] ?? []
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getVatCategory(): VatCategory
    {
        return $this->vatCategory;
    }

    public function getVatRate(): float
    {
        return $this->vatRate;
    }

    public function getVatExemptionReason(): ?VatExemptionReason
    {
        return $this->vatExemptionReason;
    }

    public function getDiscount(): float
    {
        return $this->discount;
    }

    public function getSubtotal(): float
    {
        return round(($this->quantity * $this->unitPrice) - $this->discount, 2);
    }

    public function getVatAmount(): float
    {
        return round($this->getSubtotal() * ($this->vatRate / 100), 2);
    }

    public function getTotal(): float
    {
        return round($this->getSubtotal() + $this->getVatAmount(), 2);
    }

    public function getIdentifiers(): array
    {
        return $this->identifiers;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_code' => $this->unitCode,
            'unit_price' => $this->unitPrice,
            'vat_category' => $this->vatCategory->value,
            'vat_rate' => $this->vatRate,
            'vat_exemption_reason' => $this->vatExemptionReason?->value,
            'discount' => $this->discount,
            'subtotal' => $this->getSubtotal(),
            'vat_amount' => $this->getVatAmount(),
            'total' => $this->getTotal(),
            'identifiers' => $this->identifiers,
        ];
    }
}
