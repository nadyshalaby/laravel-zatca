<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Enums\VatCategory;
use DOMElement;

/**
 * Writes the document-level totals of a UBL invoice: its allowance, tax totals and legal monetary total.
 */
class UblTotalsWriter
{
    public function __construct(
        protected readonly UblElements $elements,
    ) {}

    /**
     * Add the document-level allowance, the tax totals and the legal monetary total.
     */
    public function addTotals(DOMElement $parent, InvoiceInterface $invoice): void
    {
        // Add allowance/charge (document level)
        if ($invoice->getTotalDiscount() > 0) {
            $this->addAllowanceCharge($parent, $invoice);
        }

        // Add tax total
        $this->addTaxTotal($parent, $invoice);

        // Add legal monetary total
        $this->addLegalMonetaryTotal($parent, $invoice);
    }

    /**
     * Add allowance/charge.
     */
    protected function addAllowanceCharge(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $allowance = $this->elements->createElement('cac:AllowanceCharge');
        $this->elements->addElement($allowance, 'cbc:ChargeIndicator', 'false');
        $this->elements->addElement($allowance, 'cbc:AllowanceChargeReason', 'Discount');

        $amount = $this->elements->createElement('cbc:Amount', $this->elements->formatAmount($invoice->getTotalDiscount()));
        $amount->setAttribute('currencyID', $invoice->getCurrency());
        $allowance->appendChild($amount);

        $parent->appendChild($allowance);
    }

    /**
     * Add tax total.
     */
    protected function addTaxTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $taxTotal = $this->elements->createElement('cac:TaxTotal');

        // Total tax amount
        $taxAmount = $this->elements->createElement('cbc:TaxAmount', $this->elements->formatAmount($invoice->getTotalVat()));
        $taxAmount->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal->appendChild($taxAmount);

        // Tax subtotals by category
        foreach ($invoice->getVatBreakdown() as $breakdown) {
            $this->addTaxSubtotal($taxTotal, $breakdown, $invoice->getCurrency());
        }

        $parent->appendChild($taxTotal);

        // Add second TaxTotal for tax currency (required by ZATCA)
        $taxTotal2 = $this->elements->createElement('cac:TaxTotal');
        $taxAmount2 = $this->elements->createElement('cbc:TaxAmount', $this->elements->formatAmount($invoice->getTotalVat()));
        $taxAmount2->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal2->appendChild($taxAmount2);
        $parent->appendChild($taxTotal2);
    }

    /**
     * Add tax subtotal.
     *
     * @param  array<string, mixed>  $breakdown
     */
    protected function addTaxSubtotal(DOMElement $taxTotal, array $breakdown, string $currency): void
    {
        $subtotal = $this->elements->createElement('cac:TaxSubtotal');

        // Taxable amount
        $taxableAmount = $this->elements->createElement('cbc:TaxableAmount', $this->elements->formatAmount($breakdown['taxable_amount']));
        $taxableAmount->setAttribute('currencyID', $currency);
        $subtotal->appendChild($taxableAmount);

        // Tax amount
        $taxAmount = $this->elements->createElement('cbc:TaxAmount', $this->elements->formatAmount($breakdown['tax_amount']));
        $taxAmount->setAttribute('currencyID', $currency);
        $subtotal->appendChild($taxAmount);

        // Tax category
        $taxCategory = $this->elements->createElement('cac:TaxCategory');
        $this->elements->addElement($taxCategory, 'cbc:ID', $breakdown['category']->value);
        $this->elements->addElement($taxCategory, 'cbc:Percent', $this->elements->formatAmount($breakdown['rate']));

        // Add exemption reason if applicable
        if ($breakdown['exemption_reason'] && $breakdown['category'] !== VatCategory::STANDARD) {
            $this->elements->addElement($taxCategory, 'cbc:TaxExemptionReasonCode', $breakdown['exemption_reason']->value);
            $this->elements->addElement($taxCategory, 'cbc:TaxExemptionReason', $breakdown['exemption_reason']->description());
        }

        $this->elements->addVatTaxScheme($taxCategory);

        $subtotal->appendChild($taxCategory);
        $taxTotal->appendChild($subtotal);
    }

    /**
     * Add legal monetary total.
     */
    protected function addLegalMonetaryTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $monetary = $this->elements->createElement('cac:LegalMonetaryTotal');

        // Line extension amount (sum of line subtotals)
        $lineExt = $this->elements->createElement('cbc:LineExtensionAmount', $this->elements->formatAmount($invoice->getSubtotal()));
        $lineExt->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($lineExt);

        // Tax exclusive amount
        $taxExcl = $this->elements->createElement('cbc:TaxExclusiveAmount', $this->elements->formatAmount($invoice->getSubtotal()));
        $taxExcl->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($taxExcl);

        // Tax inclusive amount
        $taxIncl = $this->elements->createElement('cbc:TaxInclusiveAmount', $this->elements->formatAmount($invoice->getTotalWithVat()));
        $taxIncl->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($taxIncl);

        // Allowance total
        if ($invoice->getTotalDiscount() > 0) {
            $allowance = $this->elements->createElement('cbc:AllowanceTotalAmount', $this->elements->formatAmount($invoice->getTotalDiscount()));
            $allowance->setAttribute('currencyID', $invoice->getCurrency());
            $monetary->appendChild($allowance);
        }

        // Payable amount
        $payable = $this->elements->createElement('cbc:PayableAmount', $this->elements->formatAmount($invoice->getTotalWithVat()));
        $payable->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($payable);

        $parent->appendChild($monetary);
    }
}
