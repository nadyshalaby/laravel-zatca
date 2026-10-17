<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Contracts\LineItemInterface;
use DOMElement;

/**
 * Writes the invoice lines of a UBL invoice.
 */
class UblLineWriter
{
    public function __construct(
        protected readonly UblElements $elements,
    ) {}

    /**
     * Add an invoice line for each of the invoice's line items.
     */
    public function addInvoiceLines(DOMElement $parent, InvoiceInterface $invoice): void
    {
        foreach ($invoice->getLineItems() as $lineItem) {
            $this->addInvoiceLine($parent, $lineItem, $invoice->getCurrency());
        }
    }

    /**
     * Add invoice line, every figure as priced: the quantity (BT-129), the net (BT-131), the discount (BT-136),
     * the line VAT (KSA-11), the net with VAT (KSA-12) and the unit price (BT-146).
     */
    protected function addInvoiceLine(DOMElement $parent, LineItemInterface $item, string $currency): void
    {
        $line = $this->elements->createElement('cac:InvoiceLine');

        // Line ID
        $this->elements->addElement($line, 'cbc:ID', (string) $item->getId());

        // Invoiced quantity
        $quantity = $this->elements->createElement('cbc:InvoicedQuantity', $item->getQuantity());
        $quantity->setAttribute('unitCode', $item->getUnitCode());
        $line->appendChild($quantity);

        // Line extension amount: the net, the line amount less the discount (BT-131)
        $lineExt = $this->elements->createElement('cbc:LineExtensionAmount', $item->getSubtotal());
        $lineExt->setAttribute('currencyID', $currency);
        $line->appendChild($lineExt);

        // Add line-level AllowanceCharge if there's a discount (BT-136)
        if ($item->hasDiscount()) {
            $this->addLineAllowanceCharge($line, $item, $currency);
        }

        // Tax total for line
        $this->addLineTaxTotal($line, $item, $currency);

        // Item
        $this->addItem($line, $item);

        // Price (with base quantity for proper KSA-12 calculation)
        $this->addPrice($line, $item, $currency);

        $parent->appendChild($line);
    }

    /**
     * Add the line's discount as an allowance: reason code 95 and the amount alone (BT-136), with no base amount
     * and no percentage, which ZATCA allows (v1.2 §9.4, p.18) and which would otherwise have to agree with the
     * amount to the halala (BR-KSA-EN16931-03).
     */
    protected function addLineAllowanceCharge(DOMElement $line, LineItemInterface $item, string $currency): void
    {
        $allowance = $this->elements->createElement('cac:AllowanceCharge');

        // ChargeIndicator: false = allowance/discount, true = charge
        $this->elements->addElement($allowance, 'cbc:ChargeIndicator', 'false');

        // Allowance reason code (95 = Discount)
        $this->elements->addElement($allowance, 'cbc:AllowanceChargeReasonCode', '95');

        // Allowance reason
        $this->elements->addElement($allowance, 'cbc:AllowanceChargeReason', 'Discount');

        // Discount amount (BT-136)
        $amount = $this->elements->createElement('cbc:Amount', $item->getDiscount());
        $amount->setAttribute('currencyID', $currency);
        $allowance->appendChild($amount);

        $line->appendChild($allowance);
    }

    /**
     * Add the line's tax total: its VAT amount and its rounding amount.
     */
    protected function addLineTaxTotal(DOMElement $line, LineItemInterface $item, string $currency): void
    {
        $taxTotal = $this->elements->createElement('cac:TaxTotal');
        $taxAmount = $this->elements->createElement('cbc:TaxAmount', $item->getVatAmount());
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);

        // Rounding amount: the net plus the line VAT (KSA-12)
        $roundingAmount = $this->elements->createElement('cbc:RoundingAmount', $item->getTotal());
        $roundingAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($roundingAmount);

        $line->appendChild($taxTotal);
    }

    /**
     * Add the line's item: its name and classified tax category.
     */
    protected function addItem(DOMElement $line, LineItemInterface $item): void
    {
        $itemEl = $this->elements->createElement('cac:Item');
        $this->elements->addElement($itemEl, 'cbc:Name', $item->getName());

        // Classified tax category
        $taxCategory = $this->elements->createElement('cac:ClassifiedTaxCategory');
        $this->elements->addElement($taxCategory, 'cbc:ID', $item->getVatCategory()->value);
        $this->elements->addElement($taxCategory, 'cbc:Percent', $item->getVatRate());
        $this->elements->addVatTaxScheme($taxCategory);

        $itemEl->appendChild($taxCategory);
        $line->appendChild($itemEl);
    }

    /**
     * Add the line's price with its base quantity.
     */
    protected function addPrice(DOMElement $line, LineItemInterface $item, string $currency): void
    {
        $price = $this->elements->createElement('cac:Price');
        $priceAmount = $this->elements->createElement('cbc:PriceAmount', $item->getUnitPrice());
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);

        // Base quantity (defaults to 1)
        $baseQuantity = $this->elements->createElement('cbc:BaseQuantity', '1');
        $baseQuantity->setAttribute('unitCode', $item->getUnitCode());
        $price->appendChild($baseQuantity);

        $line->appendChild($price);
    }
}
