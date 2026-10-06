<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Contracts\LineItemInterface;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;
use Corecave\Zatca\Exceptions\InvoiceException;

/**
 * One invoice line, every figure as the caller priced it and written as given.
 */
class LineItem implements LineItemInterface
{
    /** A quantity: whole units with up to six decimals. */
    public const string QUANTITY_PATTERN = '/^(0|[1-9]\d{0,8})(\.\d{1,6})?$/';

    /** A VAT rate: a percentage with two decimals. */
    public const string RATE_PATTERN = '/^(0|[1-9]\d{0,2})\.\d{2}$/';

    /** The figures a line is priced with, by their array keys. */
    private const array FIGURES = ['unit_price', 'line_amount', 'net', 'vat', 'gross'];

    protected int $id;

    protected string $name;

    protected string $quantity;

    protected string $unitCode;

    protected Amount $unitPrice;

    protected Amount $lineAmount;

    protected Amount $discount;

    protected Amount $net;

    protected Amount $vat;

    protected Amount $gross;

    protected VatCategory $vatCategory;

    protected string $vatRate;

    protected ?VatExemptionReason $vatExemptionReason;

    /** @var array<string, mixed> */
    protected array $identifiers;

    /**
     * @throws InvoiceException when the quantity or the rate is not exact, or the discount is more than the line
     */
    public function __construct(LineItemData $data)
    {
        $this->id = $data->id;
        $this->name = $data->name;
        $this->quantity = $data->quantity;
        $this->unitCode = $data->unitCode;
        $this->unitPrice = $data->unitPrice;
        $this->lineAmount = $data->lineAmount;
        $this->discount = $data->discount;
        $this->net = $data->net;
        $this->vat = $data->vat;
        $this->gross = $data->gross;
        $this->vatCategory = $data->vatCategory;
        $this->vatRate = $data->vatRate;
        $this->vatExemptionReason = $data->vatExemptionReason;
        $this->identifiers = $data->identifiers;

        $this->refuseInexactLine();
    }

    /**
     * Create from array: the figures are text, as priced (`'175.05'`), and an absent discount is none.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvoiceException when a figure is missing or is not exact
     */
    public static function fromArray(array $data, int $id = 1): self
    {
        foreach ([...self::FIGURES, 'quantity', 'vat_rate'] as $field) {
            if (! isset($data[$field])) {
                throw InvoiceException::invalidLineItem($id, "{$field} is required");
            }
        }

        $line = new LineItemData;
        $line->id = $data['id'] ?? $id;
        $line->name = $data['name'];
        $line->quantity = $data['quantity'];
        $line->unitCode = $data['unit_code'] ?? 'PCE';
        $line->vatCategory = self::category($data['vat_category'] ?? VatCategory::STANDARD);
        $line->vatRate = $data['vat_rate'];
        $line->vatExemptionReason = self::exemptionReason($data['vat_exemption_reason'] ?? null);
        $line->identifiers = $data['identifiers'] ?? [];

        return new self(self::withFigures($line, $data));
    }

    /**
     * The line with its priced figures read from the array.
     *
     * @param  array<string, mixed>  $data
     */
    private static function withFigures(LineItemData $line, array $data): LineItemData
    {
        $line->unitPrice = Amount::of($data['unit_price']);
        $line->lineAmount = Amount::of($data['line_amount']);
        $line->discount = Amount::of($data['discount'] ?? '0.00');
        $line->net = Amount::of($data['net']);
        $line->vat = Amount::of($data['vat']);
        $line->gross = Amount::of($data['gross']);

        return $line;
    }

    private static function category(VatCategory|string $category): VatCategory
    {
        return $category instanceof VatCategory ? $category : VatCategory::from($category);
    }

    private static function exemptionReason(VatExemptionReason|string|null $reason): ?VatExemptionReason
    {
        return is_string($reason) ? VatExemptionReason::from($reason) : $reason;
    }

    /**
     * @throws InvoiceException
     */
    private function refuseInexactLine(): void
    {
        if (preg_match(self::QUANTITY_PATTERN, $this->quantity) !== 1 || preg_match('/^0(\.0+)?$/', $this->quantity) === 1) {
            throw InvoiceException::invalidLineItem($this->id, "its quantity {$this->quantity} is not a number above 0 with up to six decimals");
        }

        if (preg_match(self::RATE_PATTERN, $this->vatRate) !== 1) {
            throw InvoiceException::invalidLineItem($this->id, "its VAT rate {$this->vatRate} is not a percentage with two decimals");
        }

        if ($this->discount->isGreaterThan($this->lineAmount)) {
            throw InvoiceException::discountExceedsLine($this->id, $this->discount->value, $this->lineAmount->value);
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice->value;
    }

    public function getVatCategory(): VatCategory
    {
        return $this->vatCategory;
    }

    public function getVatRate(): string
    {
        return $this->vatRate;
    }

    public function getVatExemptionReason(): ?VatExemptionReason
    {
        return $this->vatExemptionReason;
    }

    public function getLineAmount(): string
    {
        return $this->lineAmount->value;
    }

    public function getDiscount(): string
    {
        return $this->discount->value;
    }

    public function hasDiscount(): bool
    {
        return ! $this->discount->isZero();
    }

    public function getSubtotal(): string
    {
        return $this->net->value;
    }

    public function getVatAmount(): string
    {
        return $this->vat->value;
    }

    public function getTotal(): string
    {
        return $this->gross->value;
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
            'unit_price' => $this->unitPrice->value,
            'vat_category' => $this->vatCategory->value,
            'vat_rate' => $this->vatRate,
            'vat_exemption_reason' => $this->vatExemptionReason?->value,
            'line_amount' => $this->lineAmount->value,
            'discount' => $this->discount->value,
            'net' => $this->net->value,
            'vat' => $this->vat->value,
            'gross' => $this->gross->value,
            'identifiers' => $this->identifiers,
        ];
    }
}
