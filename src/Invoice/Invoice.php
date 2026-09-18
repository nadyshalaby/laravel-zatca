<?php

namespace Corecave\Zatca\Invoice;

use Carbon\Carbon;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Contracts\LineItemInterface;
use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;
use Corecave\Zatca\Enums\PaymentMethod;
use DateTimeInterface;

class Invoice implements InvoiceInterface
{
    protected string $uuid;

    protected string $invoiceNumber;

    protected InvoiceType $type;

    protected InvoiceSubType $subType;

    protected Carbon $issueDate;

    protected ?Carbon $supplyDate;

    protected array $seller;

    protected ?array $buyer;

    /** @var LineItemInterface[] */
    protected array $lineItems = [];

    protected string $currency;

    protected int $icv;

    protected string $previousInvoiceHash;

    protected ?string $hash = null;

    protected ?PaymentMethod $paymentMethod;

    protected ?string $paymentTerms;

    protected ?string $originalInvoiceReference;

    protected ?string $instructionNote;

    protected array $notes = [];

    public function __construct(
        string $uuid,
        string $invoiceNumber,
        InvoiceType $type,
        InvoiceSubType $subType,
        Carbon $issueDate,
        array $seller,
        int $icv,
        string $previousInvoiceHash,
        ?array $buyer = null,
        ?Carbon $supplyDate = null,
        string $currency = 'SAR',
        ?PaymentMethod $paymentMethod = null,
        ?string $paymentTerms = null,
        ?string $originalInvoiceReference = null,
        ?string $instructionNote = null,
        array $notes = []
    ) {
        $this->uuid = $uuid;
        $this->invoiceNumber = $invoiceNumber;
        $this->type = $type;
        $this->subType = $subType;
        $this->issueDate = $issueDate;
        $this->seller = $seller;
        $this->icv = $icv;
        $this->previousInvoiceHash = $previousInvoiceHash;
        $this->buyer = $buyer;
        $this->supplyDate = $supplyDate;
        $this->currency = $currency;
        $this->paymentMethod = $paymentMethod;
        $this->paymentTerms = $paymentTerms;
        $this->originalInvoiceReference = $originalInvoiceReference;
        $this->instructionNote = $instructionNote;
        $this->notes = $notes;
    }

    /**
     * Add a line item to the invoice.
     */
    public function addLineItem(LineItemInterface $item): self
    {
        $this->lineItems[] = $item;

        return $this;
    }

    /**
     * Set line items.
     *
     * @param  LineItemInterface[]  $items
     */
    public function setLineItems(array $items): self
    {
        $this->lineItems = $items;

        return $this;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getType(): InvoiceType
    {
        return $this->type;
    }

    public function getSubType(): InvoiceSubType
    {
        return $this->subType;
    }

    public function getTypeCode(): string
    {
        return $this->type->value;
    }

    public function getSubTypeCode(): string
    {
        return $this->subType->value;
    }

    public function getIssueDate(): DateTimeInterface
    {
        return $this->issueDate;
    }

    public function getSupplyDate(): ?DateTimeInterface
    {
        return $this->supplyDate;
    }

    public function getSeller(): array
    {
        return $this->seller;
    }

    public function getSellerName(): string
    {
        return $this->seller['name_ar'] ?? $this->seller['name'] ?? '';
    }

    public function getSellerVatNumber(): string
    {
        return $this->seller['vat_number'] ?? '';
    }

    public function getBuyer(): ?array
    {
        return $this->buyer;
    }

    public function getLineItems(): array
    {
        return $this->lineItems;
    }

    public function getSubtotal(): float
    {
        return round(array_reduce(
            $this->lineItems,
            fn ($carry, LineItemInterface $item) => $carry + $item->getSubtotal(),
            0
        ), 2);
    }

    public function getTotalVat(): float
    {
        return round(array_reduce(
            $this->lineItems,
            fn ($carry, LineItemInterface $item) => $carry + $item->getVatAmount(),
            0
        ), 2);
    }

    public function getTotalWithVat(): float
    {
        return round($this->getSubtotal() + $this->getTotalVat(), 2);
    }

    public function getTotalDiscount(): float
    {
        return round(array_reduce(
            $this->lineItems,
            fn ($carry, LineItemInterface $item) => $carry + $item->getDiscount(),
            0
        ), 2);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getIcv(): int
    {
        return $this->icv;
    }

    public function getPreviousInvoiceHash(): string
    {
        return $this->previousInvoiceHash;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(string $hash): void
    {
        $this->hash = $hash;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod?->value;
    }

    public function getPaymentMethodEnum(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getPaymentTerms(): ?string
    {
        return $this->paymentTerms;
    }

    public function isSimplified(): bool
    {
        return $this->subType->isSimplified();
    }

    public function isStandard(): bool
    {
        return $this->subType->isStandard();
    }

    public function isCreditNote(): bool
    {
        return $this->type === InvoiceType::CREDIT_NOTE;
    }

    public function isDebitNote(): bool
    {
        return $this->type === InvoiceType::DEBIT_NOTE;
    }

    public function getOriginalInvoiceReference(): ?string
    {
        return $this->originalInvoiceReference;
    }

    public function getInstructionNote(): ?string
    {
        return $this->instructionNote;
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function addNote(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function getVatBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->lineItems as $item) {
            $category = $item->getVatCategory()->value;
            $rate = $item->getVatRate();
            $key = "{$category}_{$rate}";

            if (! isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'category' => $item->getVatCategory(),
                    'rate' => $rate,
                    'taxable_amount' => 0,
                    'tax_amount' => 0,
                    'exemption_reason' => $item->getVatExemptionReason(),
                ];
            }

            $breakdown[$key]['taxable_amount'] += $item->getSubtotal();
            $breakdown[$key]['tax_amount'] += $item->getVatAmount();
        }

        // Round the totals
        foreach ($breakdown as $key => $data) {
            $breakdown[$key]['taxable_amount'] = round($data['taxable_amount'], 2);
            $breakdown[$key]['tax_amount'] = round($data['tax_amount'], 2);
        }

        return array_values($breakdown);
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'invoice_number' => $this->invoiceNumber,
            'type' => $this->type->value,
            'sub_type' => $this->subType->value,
            'issue_date' => $this->issueDate->toDateTimeString(),
            'supply_date' => $this->supplyDate?->toDateTimeString(),
            'seller' => $this->seller,
            'buyer' => $this->buyer,
            'line_items' => array_map(fn ($item) => $item->toArray(), $this->lineItems),
            'subtotal' => $this->getSubtotal(),
            'total_vat' => $this->getTotalVat(),
            'total_with_vat' => $this->getTotalWithVat(),
            'total_discount' => $this->getTotalDiscount(),
            'currency' => $this->currency,
            'icv' => $this->icv,
            'previous_invoice_hash' => $this->previousInvoiceHash,
            'hash' => $this->hash,
            'payment_method' => $this->paymentMethod?->value,
            'payment_terms' => $this->paymentTerms,
            'original_invoice_reference' => $this->originalInvoiceReference,
            'instruction_note' => $this->instructionNote,
            'notes' => $this->notes,
            'vat_breakdown' => $this->getVatBreakdown(),
        ];
    }
}
