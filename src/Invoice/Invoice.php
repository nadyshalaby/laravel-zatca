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

    /** @var array<string, mixed> */
    protected array $seller;

    /** @var array<string, mixed>|null */
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

    /** @var array<int, string> */
    protected array $notes = [];

    /** @var list<VatGroup> */
    protected array $vatGroups;

    protected DocumentTotals $totals;

    public function __construct(InvoiceData $data)
    {
        $this->uuid = $data->uuid;
        $this->invoiceNumber = $data->invoiceNumber;
        $this->type = $data->type;
        $this->subType = $data->subType;
        $this->issueDate = $data->issueDate;
        $this->seller = $data->seller;
        $this->icv = $data->icv;
        $this->previousInvoiceHash = $data->previousInvoiceHash;
        $this->buyer = $data->buyer;
        $this->supplyDate = $data->supplyDate;
        $this->currency = $data->currency;
        $this->paymentMethod = $data->paymentMethod;
        $this->paymentTerms = $data->paymentTerms;
        $this->originalInvoiceReference = $data->originalInvoiceReference;
        $this->instructionNote = $data->instructionNote;
        $this->notes = $data->notes;
        $this->vatGroups = $data->vatGroups;
        $this->totals = $data->totals;
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

    public function getSubtotal(): string
    {
        return $this->totals->net->value;
    }

    public function getTotalVat(): string
    {
        return $this->totals->vat->value;
    }

    public function getTotalWithVat(): string
    {
        return $this->totals->gross->value;
    }

    public function getTotals(): DocumentTotals
    {
        return $this->totals;
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
        return $this->vatGroups;
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
            'totals' => $this->totals->toArray(),
            'currency' => $this->currency,
            'icv' => $this->icv,
            'previous_invoice_hash' => $this->previousInvoiceHash,
            'hash' => $this->hash,
            'payment_method' => $this->paymentMethod?->value,
            'payment_terms' => $this->paymentTerms,
            'original_invoice_reference' => $this->originalInvoiceReference,
            'instruction_note' => $this->instructionNote,
            'notes' => $this->notes,
            'vat_breakdown' => array_map(fn (VatGroup $group) => $group->toArray(), $this->vatGroups),
        ];
    }
}
