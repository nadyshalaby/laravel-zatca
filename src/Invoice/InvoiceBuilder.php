<?php

namespace Corecave\Zatca\Invoice;

use Carbon\Carbon;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;
use Corecave\Zatca\Enums\PaymentMethod;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Exceptions\InvoiceException;
use Corecave\Zatca\Hash\HashChainManager;
use Illuminate\Support\Str;

class InvoiceBuilder
{
    protected array $sellerConfig;

    protected array $invoiceConfig;

    protected ?string $uuid = null;

    protected ?string $invoiceNumber = null;

    protected InvoiceType $type = InvoiceType::INVOICE;

    protected ?InvoiceSubType $subType = null;

    protected ?Carbon $issueDate = null;

    protected ?Carbon $supplyDate = null;

    protected ?array $seller = null;

    protected ?array $buyer = null;

    protected array $lineItems = [];

    protected string $currency;

    protected ?int $icv = null;

    protected ?string $previousInvoiceHash = null;

    protected ?PaymentMethod $paymentMethod = null;

    protected ?string $paymentTerms = null;

    protected ?string $originalInvoiceReference = null;

    protected ?string $instructionNote = null;

    protected array $notes = [];

    protected bool $isSimplified = false;

    protected bool $isCreditNote = false;

    protected bool $isDebitNote = false;

    public function __construct(array $sellerConfig = [], array $invoiceConfig = [])
    {
        $this->sellerConfig = $sellerConfig;
        $this->invoiceConfig = $invoiceConfig;
        $this->currency = $invoiceConfig['currency'] ?? 'SAR';
    }

    /**
     * Create a standard (B2B) invoice builder.
     */
    public static function standard(): self
    {
        $builder = new self(
            config('zatca.seller', []),
            config('zatca.invoice', [])
        );

        $builder->isSimplified = false;
        $builder->type = InvoiceType::INVOICE;
        $builder->subType = InvoiceSubType::STANDARD;

        return $builder;
    }

    /**
     * Create a simplified (B2C) invoice builder.
     */
    public static function simplified(): self
    {
        $builder = new self(
            config('zatca.seller', []),
            config('zatca.invoice', [])
        );

        $builder->isSimplified = true;
        $builder->type = InvoiceType::INVOICE;
        $builder->subType = InvoiceSubType::SIMPLIFIED;

        return $builder;
    }

    /**
     * Create a credit note builder.
     */
    public static function creditNote(bool $simplified = false): self
    {
        $builder = new self(
            config('zatca.seller', []),
            config('zatca.invoice', [])
        );

        $builder->isSimplified = $simplified;
        $builder->isCreditNote = true;
        $builder->type = InvoiceType::CREDIT_NOTE;
        $builder->subType = $simplified
            ? InvoiceSubType::SIMPLIFIED
            : InvoiceSubType::STANDARD;

        return $builder;
    }

    /**
     * Create a debit note builder.
     */
    public static function debitNote(bool $simplified = false): self
    {
        $builder = new self(
            config('zatca.seller', []),
            config('zatca.invoice', [])
        );

        $builder->isSimplified = $simplified;
        $builder->isDebitNote = true;
        $builder->type = InvoiceType::DEBIT_NOTE;
        $builder->subType = $simplified
            ? InvoiceSubType::SIMPLIFIED
            : InvoiceSubType::STANDARD;

        return $builder;
    }

    /**
     * Set the invoice UUID.
     */
    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    /**
     * Set the invoice number.
     */
    public function setInvoiceNumber(string $number): self
    {
        $this->invoiceNumber = $number;

        return $this;
    }

    /**
     * Set the issue date.
     */
    public function setIssueDate(Carbon|string $date): self
    {
        $this->issueDate = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $this;
    }

    /**
     * Set the supply date.
     */
    public function setSupplyDate(Carbon|string $date): self
    {
        $this->supplyDate = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $this;
    }

    /**
     * Set seller information.
     */
    public function setSeller(array $seller): self
    {
        $this->seller = $seller;

        return $this;
    }

    /**
     * Set buyer information.
     */
    public function setBuyer(array $buyer): self
    {
        $this->buyer = $buyer;

        return $this;
    }

    /**
     * Add a line item.
     */
    public function addLineItem(array $item): self
    {
        $this->lineItems[] = $item;

        return $this;
    }

    /**
     * Set all line items.
     */
    public function setLineItems(array $items): self
    {
        $this->lineItems = $items;

        return $this;
    }

    /**
     * Set the currency.
     */
    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Set the Invoice Counter Value (ICV).
     */
    public function setIcv(int $icv): self
    {
        $this->icv = $icv;

        return $this;
    }

    /**
     * Set the Previous Invoice Hash (PIH).
     */
    public function setPreviousInvoiceHash(string $hash): self
    {
        $this->previousInvoiceHash = $hash;

        return $this;
    }

    /**
     * Set the payment method.
     */
    public function setPaymentMethod(PaymentMethod|string $method): self
    {
        $this->paymentMethod = $method instanceof PaymentMethod
            ? $method
            : PaymentMethod::from($method);

        return $this;
    }

    /**
     * Set payment terms/notes.
     */
    public function setPaymentTerms(string $terms): self
    {
        $this->paymentTerms = $terms;

        return $this;
    }

    /**
     * Set the original invoice reference (for credit/debit notes).
     */
    public function setOriginalInvoice(string $reference): self
    {
        $this->originalInvoiceReference = $reference;

        return $this;
    }

    /**
     * Set the instruction note (reason for credit/debit note).
     */
    public function setReason(string $reason): self
    {
        $this->instructionNote = $reason;

        return $this;
    }

    /**
     * Add a note to the invoice.
     */
    public function addNote(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    /**
     * Set the invoice sub-type.
     */
    public function setSubType(InvoiceSubType $subType): self
    {
        $this->subType = $subType;

        return $this;
    }

    /**
     * Build the invoice.
     *
     * @throws InvoiceException
     */
    public function build(): InvoiceInterface
    {
        $this->prepareDefaults();
        $this->validate();

        $invoice = new Invoice(
            uuid: $this->uuid,
            invoiceNumber: $this->invoiceNumber,
            type: $this->type,
            subType: $this->subType,
            issueDate: $this->issueDate,
            seller: $this->seller,
            icv: $this->icv,
            previousInvoiceHash: $this->previousInvoiceHash,
            buyer: $this->buyer,
            supplyDate: $this->supplyDate,
            currency: $this->currency,
            paymentMethod: $this->paymentMethod,
            paymentTerms: $this->paymentTerms,
            originalInvoiceReference: $this->originalInvoiceReference,
            instructionNote: $this->instructionNote,
            notes: $this->notes
        );

        // Add line items
        foreach ($this->lineItems as $index => $itemData) {
            $item = LineItem::fromArray($itemData, $index + 1);
            $invoice->addLineItem($item);
        }

        return $invoice;
    }

    /**
     * Prepare default values.
     */
    protected function prepareDefaults(): void
    {
        // Generate UUID if not set
        if ($this->uuid === null) {
            $this->uuid = (string) Str::uuid();
        }

        // Set issue date to now if not set
        if ($this->issueDate === null) {
            $this->issueDate = Carbon::now();
        }

        // Use seller config if not set
        if ($this->seller === null) {
            $this->seller = $this->sellerConfig;
        }

        // Get ICV and PIH from hash chain manager if not set
        if ($this->icv === null || $this->previousInvoiceHash === null) {
            $hashManager = app(HashChainManager::class);

            if ($this->icv === null) {
                $this->icv = $hashManager->getNextIcv();
            }

            if ($this->previousInvoiceHash === null) {
                $this->previousInvoiceHash = $hashManager->getPreviousInvoiceHash();
            }
        }

        // Set default sub-type based on flags
        if ($this->subType === null) {
            $this->subType = $this->determineSubType();
        }
    }

    /**
     * Determine the invoice sub-type.
     */
    protected function determineSubType(): InvoiceSubType
    {
        // The sub-type is determined by whether it's simplified (B2C) or standard (B2B)
        // The invoice type (invoice/credit/debit) is determined by InvoiceType enum
        return $this->isSimplified
            ? InvoiceSubType::SIMPLIFIED
            : InvoiceSubType::STANDARD;
    }

    /**
     * Validate the invoice data.
     *
     * @throws InvoiceException
     */
    protected function validate(): void
    {
        if (empty($this->invoiceNumber)) {
            throw InvoiceException::missingField('invoice_number');
        }

        if (empty($this->seller)) {
            throw InvoiceException::missingField('seller');
        }

        if (empty($this->seller['vat_number'])) {
            throw InvoiceException::missingField('seller.vat_number');
        }

        if (empty($this->seller['name']) && empty($this->seller['name_ar'])) {
            throw InvoiceException::missingField('seller.name');
        }

        if (empty($this->lineItems)) {
            throw InvoiceException::noLineItems();
        }

        // Standard invoices require buyer information
        if (! $this->isSimplified && empty($this->buyer)) {
            throw InvoiceException::buyerRequired();
        }

        // Credit/debit notes require original invoice reference
        if (($this->isCreditNote || $this->isDebitNote) && empty($this->originalInvoiceReference)) {
            throw InvoiceException::missingOriginalInvoice();
        }

        // Validate line items
        foreach ($this->lineItems as $index => $item) {
            $this->validateLineItem($item, $index);
        }
    }

    /**
     * Validate a line item.
     *
     * @throws InvoiceException
     */
    protected function validateLineItem(array $item, int $index): void
    {
        if (empty($item['name'])) {
            throw InvoiceException::invalidLineItem($index, 'name is required');
        }

        if (! isset($item['quantity']) || $item['quantity'] <= 0) {
            throw InvoiceException::invalidLineItem($index, 'quantity must be greater than 0');
        }

        if (! isset($item['unit_price']) || $item['unit_price'] < 0) {
            throw InvoiceException::invalidLineItem($index, 'unit_price must be 0 or greater');
        }
    }

    /**
     * Create a sample invoice for compliance testing.
     */
    public function sample(): InvoiceInterface
    {
        return $this
            ->setInvoiceNumber('SME00001')
            ->setIssueDate(Carbon::now())
            ->addLineItem([
                'name' => 'Sample Item',
                'quantity' => 1,
                'unit_price' => 100.00,
                'vat_category' => VatCategory::STANDARD,
                'vat_rate' => 15.00,
            ])
            ->build();
    }
}
