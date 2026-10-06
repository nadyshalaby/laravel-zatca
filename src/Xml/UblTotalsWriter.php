<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Invoice\VatGroup;
use DOMElement;

/**
 * Writes the document-level totals of a UBL invoice, as priced: its tax totals and legal monetary total.
 *
 * No document-level allowance is written: every discount sits on its line (BT-136), so the total without VAT is
 * the sum of the line nets (BR-CO-13), and a document allowance would need a VAT category of its own (BR-32).
 */
class UblTotalsWriter
{
    public function __construct(
        protected readonly UblElements $elements,
    ) {}

    /**
     * Add the tax totals and the legal monetary total.
     */
    public function addTotals(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $this->addTaxTotal($parent, $invoice);
        $this->addLegalMonetaryTotal($parent, $invoice);
    }

    /**
     * Add the tax total with one subtotal per VAT group, then the second tax total ZATCA asks for.
     */
    protected function addTaxTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $taxTotal = $this->elements->createElement('cac:TaxTotal');
        $taxTotal->appendChild($this->amount('cbc:TaxAmount', $invoice->getTotalVat(), $invoice->getCurrency()));

        foreach ($invoice->getVatBreakdown() as $group) {
            $this->addTaxSubtotal($taxTotal, $group, $invoice->getCurrency());
        }

        $parent->appendChild($taxTotal);

        // Add second TaxTotal for tax currency (required by ZATCA)
        $taxTotal2 = $this->elements->createElement('cac:TaxTotal');
        $taxTotal2->appendChild($this->amount('cbc:TaxAmount', $invoice->getTotalVat(), $invoice->getCurrency()));
        $parent->appendChild($taxTotal2);
    }

    /**
     * Add one VAT group's subtotal: its taxable amount (BT-116) and its tax (BT-117) as priced, its category and rate.
     */
    protected function addTaxSubtotal(DOMElement $taxTotal, VatGroup $group, string $currency): void
    {
        $subtotal = $this->elements->createElement('cac:TaxSubtotal');
        $subtotal->appendChild($this->amount('cbc:TaxableAmount', $group->taxable->value, $currency));
        $subtotal->appendChild($this->amount('cbc:TaxAmount', $group->tax->value, $currency));

        $taxCategory = $this->elements->createElement('cac:TaxCategory');
        $this->elements->addElement($taxCategory, 'cbc:ID', $group->category->value);
        $this->elements->addElement($taxCategory, 'cbc:Percent', $group->rate);

        // Add exemption reason if applicable
        if ($group->exemptionReason !== null && $group->category !== VatCategory::STANDARD) {
            $this->elements->addElement($taxCategory, 'cbc:TaxExemptionReasonCode', $group->exemptionReason->value);
            $this->elements->addElement($taxCategory, 'cbc:TaxExemptionReason', $group->exemptionReason->description());
        }

        $this->elements->addVatTaxScheme($taxCategory);

        $subtotal->appendChild($taxCategory);
        $taxTotal->appendChild($subtotal);
    }

    /**
     * Add the legal monetary total: the line nets (BT-106), the total without VAT (BT-109, the same), the total with
     * VAT (BT-112) and the payable amount (BT-115, the same).
     */
    protected function addLegalMonetaryTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $currency = $invoice->getCurrency();
        $monetary = $this->elements->createElement('cac:LegalMonetaryTotal');
        $monetary->appendChild($this->amount('cbc:LineExtensionAmount', $invoice->getSubtotal(), $currency));
        $monetary->appendChild($this->amount('cbc:TaxExclusiveAmount', $invoice->getSubtotal(), $currency));
        $monetary->appendChild($this->amount('cbc:TaxInclusiveAmount', $invoice->getTotalWithVat(), $currency));
        $monetary->appendChild($this->amount('cbc:PayableAmount', $invoice->getTotalWithVat(), $currency));

        $parent->appendChild($monetary);
    }

    /**
     * An amount element in the document's currency.
     */
    protected function amount(string $name, string $amount, string $currency): DOMElement
    {
        $element = $this->elements->createElement($name, $amount);
        $element->setAttribute('currencyID', $currency);

        return $element;
    }
}
