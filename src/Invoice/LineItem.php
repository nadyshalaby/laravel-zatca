<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Contracts\LineItemInterface;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;
use Corecave\Zatca\Exceptions\InvoiceException;

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

    public function __construct(LineItemData $data)
    {
        $this->id = $data->id;
        $this->name = $data->name;
        $this->quantity = $data->quantity;
        $this->unitPrice = $data->unitPrice;
        $this->vatCategory = $data->vatCategory;
        $this->vatRate = $data->vatRate ?? $data->vatCategory->defaultRate();
        $this->unitCode = $data->unitCode;
        $this->discount = $data->discount;
        $this->vatExemptionReason = $data->vatExemptionReason;
        $this->identifiers = $data->identifiers;

        // Compared to the halala, so a float reading the line a hair short never refuses a discount of all of it
        if (round($this->discount - $this->quantity * $this->unitPrice, 2) > 0) {
            throw InvoiceException::discountExceedsLine($this->id, $this->discount, $this->quantity * $this->unitPrice);
        }
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

        $line = new LineItemData;
        $line->id = $data['id'] ?? $id;
        $line->name = $data['name'];
        $line->quantity = (float) $data['quantity'];
        $line->unitPrice = (float) $data['unit_price'];
        $line->vatCategory = $vatCategory;
        $line->vatRate = $data['vat_rate'] ?? null;
        $line->unitCode = $data['unit_code'] ?? 'PCE';
        $line->discount = (float) ($data['discount'] ?? 0);
        $line->vatExemptionReason = $vatExemptionReason;
        $line->identifiers = $data['identifiers'] ?? [];

        return new self($line);
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
