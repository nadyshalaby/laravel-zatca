<?php

namespace Corecave\Zatca\Xml;

use DOMDocument;
use DOMElement;

/**
 * Creates the elements of one UBL document and formats the amounts they carry.
 */
class UblElements
{
    public function __construct(
        protected readonly DOMDocument $doc,
    ) {}

    /**
     * Create an element in the correct namespace without redundant declarations.
     *
     * This method creates elements using the document's createElement method
     * which avoids the redundant namespace declaration issue that occurs
     * when using createElementNS before appending to a parent.
     */
    public function createElement(string $qualifiedName, ?string $value = null): DOMElement
    {
        if ($value !== null) {
            $element = $this->doc->createElement($qualifiedName, htmlspecialchars($value, ENT_XML1));
        } else {
            $element = $this->doc->createElement($qualifiedName);
        }

        return $element;
    }

    /**
     * Add a simple element.
     */
    public function addElement(DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $this->createElement($name, $value);
        $parent->appendChild($element);

        return $element;
    }

    /**
     * Add the VAT tax scheme (cac:TaxScheme with the ID VAT).
     */
    public function addVatTaxScheme(DOMElement $parent): void
    {
        $taxScheme = $this->createElement('cac:TaxScheme');
        $this->addElement($taxScheme, 'cbc:ID', 'VAT');
        $parent->appendChild($taxScheme);
    }

    /**
     * Format amount for XML.
     */
    public function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
