<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Enums\VatExemptionReason;
use Corecave\Zatca\Exceptions\InvoiceException;

/**
 * One VAT group of a document as the caller priced it (BT-116 to BT-121): the lines of one category at one rate,
 * their taxable total, and the tax rounded once from that total, never summed from the lines' own VAT.
 */
final class VatGroup
{
    public function __construct(// law-exception: a data class takes one promoted parameter per field
        public readonly VatCategory $category,
        public readonly string $rate,
        public readonly Amount $taxable,
        public readonly Amount $tax,
        public readonly ?VatExemptionReason $exemptionReason = null,
    ) {
        if (preg_match(LineItem::RATE_PATTERN, $rate) !== 1) {
            throw InvoiceException::invalidField('vat_groups.rate', "{$rate} is not a percentage with two decimals");
        }
    }

    /**
     * @param  array<string, mixed>  $data  the category, the rate, the taxable amount and the tax, with any exemption reason
     *
     * @throws InvoiceException when a figure is missing or is not exact
     */
    public static function fromArray(array $data): self
    {
        foreach (['category', 'rate', 'taxable', 'tax'] as $field) {
            if (! isset($data[$field])) {
                throw InvoiceException::missingField("vat_groups.{$field}");
            }
        }

        $exemption = $data['exemption_reason'] ?? null;

        return new self(
            $data['category'] instanceof VatCategory ? $data['category'] : VatCategory::from($data['category']),
            $data['rate'],
            Amount::of($data['taxable']),
            Amount::of($data['tax']),
            is_string($exemption) ? VatExemptionReason::from($exemption) : $exemption,
        );
    }

    /**
     * @return array{category: string, rate: string, taxable: string, tax: string, exemption_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'rate' => $this->rate,
            'taxable' => $this->taxable->value,
            'tax' => $this->tax->value,
            'exemption_reason' => $this->exemptionReason?->value,
        ];
    }
}
