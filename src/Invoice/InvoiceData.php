<?php

namespace Corecave\Zatca\Invoice;

use Carbon\Carbon;
use Corecave\Zatca\Enums\InvoiceSubType;
use Corecave\Zatca\Enums\InvoiceType;
use Corecave\Zatca\Enums\PaymentMethod;

/**
 * Everything an invoice is made of apart from its lines, as the invoice builder hands it over.
 *
 * Every property without a default must be filled in before an invoice is made from it.
 */
class InvoiceData
{
    public string $uuid;

    public string $invoiceNumber;

    public InvoiceType $type;

    public InvoiceSubType $subType;

    public Carbon $issueDate;

    /** @var array<string, mixed> */
    public array $seller;

    public int $icv;

    public string $previousInvoiceHash;

    /** @var array<string, mixed>|null */
    public ?array $buyer = null;

    public ?Carbon $supplyDate = null;

    public string $currency = 'SAR';

    public ?PaymentMethod $paymentMethod = null;

    public ?string $paymentTerms = null;

    public ?string $originalInvoiceReference = null;

    public ?string $instructionNote = null;

    /** @var array<int, string> */
    public array $notes = [];

    /** @var list<VatGroup> the VAT groups as priced, at least one */
    public array $vatGroups = [];

    /** The totals as priced. */
    public DocumentTotals $totals;
}
