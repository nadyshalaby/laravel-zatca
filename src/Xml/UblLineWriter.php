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
     * Add invoice line.
     *
     * Per ZATCA KSA-13 business rule:
     * LineExtensionAmount (BT-131) = KSA-12 - BT-136 + BT-141
     * Where:
     * - KSA-12: Line item base amount (Quantity × Unit Price)
     * - BT-136: Allowance amount (discount)
     * - BT-141: Charge amount
     */
    protected function addInvoiceLine(DOMElement $parent, LineItemInterface $item, string $currency): void
    {
        $line = $this->elements->createElement('cac:InvoiceLine');

        // Line ID
        $this->elements->addElement($line, 'cbc:ID', (string) $item->getId());

        // Invoiced quantity
        $quantity = $this->elements->createElement('cbc:InvoicedQuantity', $this->formatQuantity($item->getQuantity()));
        $quantity->setAttribute('unitCode', $item->getUnitCode());
        $line->appendChild($quantity);

        // Line extension amount (net amount after discount: KSA-12 - BT-136)
        $lineExt = $this->elements->createElement('cbc:LineExtensionAmount', $this->elements->formatAmount($item->getSubtotal()));
        $lineExt->setAttribute('currencyID', $currency);
        $line->appendChild($lineExt);

        // Add line-level AllowanceCharge if there's a discount (BT-136)
        $discount = $item->getDiscount();
        if ($discount > 0) {
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
     * Add line-level allowance/charge for discounts.
     *
     * Per ZATCA requirements, line-level discounts must be represented as
     * AllowanceCharge elements with:
     * - ChargeIndicator = false (for discount/allowance)
     * - AllowanceChargeReason = reason text
     * - Amount = discount amount (BT-136)
     * - BaseAmount = original amount before discount (KSA-12)
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

        // Multiplier factor (discount percentage if applicable)
        $baseAmount = $item->getQuantity() * $item->getUnitPrice();
        if ($baseAmount > 0) {
            $discountPercent = ($item->getDiscount() / $baseAmount) * 100;
            $this->elements->addElement($allowance, 'cbc:MultiplierFactorNumeric', $this->elements->formatAmount($discountPercent));
        }

        // Discount amount (BT-136)
        $amount = $this->elements->createElement('cbc:Amount', $this->elements->formatAmount($item->getDiscount()));
        $amount->setAttribute('currencyID', $currency);
        $allowance->appendChild($amount);

        // Base amount (KSA-12: Quantity × Unit Price)
        $base = $this->elements->createElement('cbc:BaseAmount', $this->elements->formatAmount($baseAmount));
        $base->setAttribute('currencyID', $currency);
        $allowance->appendChild($base);

        $line->appendChild($allowance);
    }

    /**
     * Add the line's tax total: its VAT amount and its rounding amount.
     */
    protected function addLineTaxTotal(DOMElement $line, LineItemInterface $item, string $currency): void
    {
        $taxTotal = $this->elements->createElement('cac:TaxTotal');
        $taxAmount = $this->elements->createElement('cbc:TaxAmount', $this->elements->formatAmount($item->getVatAmount()));
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);

        // Rounding amount (required)
        $roundingAmount = $this->elements->createElement('cbc:RoundingAmount', $this->elements->formatAmount($item->getTotal()));
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
        $this->elements->addElement($taxCategory, 'cbc:Percent', $this->elements->formatAmount($item->getVatRate()));
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
        $priceAmount = $this->elements->createElement('cbc:PriceAmount', $this->elements->formatAmount($item->getUnitPrice()));
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);

        // Base quantity (defaults to 1)
        $baseQuantity = $this->elements->createElement('cbc:BaseQuantity', '1');
        $baseQuantity->setAttribute('unitCode', $item->getUnitCode());
        $price->appendChild($baseQuantity);

        $line->appendChild($price);
    }

    /**
     * Format quantity for XML.
     */
    protected function formatQuantity(float $quantity): string
    {
        // Use up to 5 decimal places for quantity
        return rtrim(rtrim(number_format($quantity, 5, '.', ''), '0'), '.');
    }
}
