<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Exceptions\ValidationException;
use DOMDocument;

class XmlValidator
{
    /**
     * Validate XML against ZATCA requirements.
     *
     * @throws ValidationException
     */
    public function validate(string $xml): bool
    {
        $errors = [];

        // Basic XML validation
        if (! $this->isWellFormed($xml)) {
            throw ValidationException::fromErrors(['XML is not well-formed']);
        }

        $doc = new DOMDocument;
        $doc->loadXML($xml);

        // Validate required elements
        $errors = array_merge($errors, $this->validateRequiredElements($doc));

        // Validate invoice type
        $errors = array_merge($errors, $this->validateInvoiceType($doc));

        // Validate seller
        $errors = array_merge($errors, $this->validateSeller($doc));

        // Validate line items
        $errors = array_merge($errors, $this->validateLineItems($doc));

        // Validate totals
        $errors = array_merge($errors, $this->validateTotals($doc));

        if (! empty($errors)) {
            throw ValidationException::fromErrors($errors);
        }

        return true;
    }

    /**
     * Check if XML is well-formed.
     */
    public function isWellFormed(string $xml): bool
    {
        libxml_use_internal_errors(true);

        $doc = new DOMDocument;
        $result = $doc->loadXML($xml);

        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $result !== false;
    }

    /**
     * Validate required elements.
     */
    protected function validateRequiredElements(DOMDocument $doc): array
    {
        return $this->missingElementErrors($this->createXPath($doc), [
            '//cbc:ID' => 'Missing required element: Invoice ID',
            '//cbc:UUID' => 'Missing required element: UUID',
            '//cbc:IssueDate' => 'Missing required element: Issue Date',
            '//cbc:InvoiceTypeCode' => 'Missing required element: Invoice Type Code',
            '//cbc:DocumentCurrencyCode' => 'Missing required element: Document Currency Code',
            '//cac:AccountingSupplierParty' => 'Missing required element: Supplier Party',
            '//cac:TaxTotal' => 'Missing required element: Tax Total',
            '//cac:LegalMonetaryTotal' => 'Missing required element: Legal Monetary Total',
            '//cac:InvoiceLine' => 'Missing required element: Invoice Line',
            // Validate ICV and PIH references
            "//cac:AdditionalDocumentReference[cbc:ID='ICV']" => 'Missing ICV (Invoice Counter Value) reference',
            "//cac:AdditionalDocumentReference[cbc:ID='PIH']" => 'Missing PIH (Previous Invoice Hash) reference',
        ]);
    }

    /**
     * Validate invoice type code.
     */
    protected function validateInvoiceType(DOMDocument $doc): array
    {
        $errors = [];
        $xpath = $this->createXPath($doc);

        $typeCode = $xpath->query('//cbc:InvoiceTypeCode');
        if ($typeCode && $typeCode->length > 0) {
            $code = $typeCode->item(0)->nodeValue;
            $validCodes = ['388', '381', '383'];

            if (! in_array($code, $validCodes)) {
                $errors[] = "Invalid invoice type code: {$code}. Must be 388 (invoice), 381 (credit note), or 383 (debit note)";
            }

            // Validate sub-type (name attribute)
            $name = $typeCode->item(0)->getAttribute('name');
            if (empty($name)) {
                $errors[] = 'Invoice type code missing name attribute (sub-type)';
            }
        }

        return $errors;
    }

    /**
     * Validate seller information.
     */
    protected function validateSeller(DOMDocument $doc): array
    {
        $errors = [];
        $xpath = $this->createXPath($doc);

        // VAT number
        $vatNumber = $xpath->query('//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
        if (! $vatNumber || $vatNumber->length === 0) {
            $errors[] = 'Missing seller VAT number';
        } else {
            $vat = $vatNumber->item(0)->nodeValue;
            if (! preg_match('/^3\d{14}$/', $vat)) {
                $errors[] = 'Seller VAT number must be 15 digits starting with 3';
            }
        }

        // Registration name
        $regName = $xpath->query('//cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');
        if (! $regName || $regName->length === 0) {
            $errors[] = 'Missing seller registration name';
        }

        // Address
        $address = $xpath->query('//cac:AccountingSupplierParty/cac:Party/cac:PostalAddress');
        if (! $address || $address->length === 0) {
            $errors[] = 'Missing seller postal address';
        }

        return $errors;
    }

    /**
     * Validate line items.
     */
    protected function validateLineItems(DOMDocument $doc): array
    {
        $errors = [];
        $xpath = $this->createXPath($doc);

        $lines = $xpath->query('//cac:InvoiceLine');

        if (! $lines || $lines->length === 0) {
            $errors[] = 'Invoice must have at least one line item';

            return $errors;
        }

        foreach ($lines as $index => $line) {
            array_push($errors, ...$this->validateLine($xpath, $line, $index + 1));
        }

        return $errors;
    }

    /**
     * Validate one invoice line's required elements.
     *
     * @return list<string>
     */
    protected function validateLine(\DOMXPath $xpath, \DOMNode $line, int $lineNum): array
    {
        $errors = $this->missingElementErrors($xpath, [
            'cbc:ID' => 'Missing line ID',
            'cbc:InvoicedQuantity' => 'Missing quantity',
            'cbc:LineExtensionAmount' => 'Missing line extension amount',
            'cac:Item/cbc:Name' => 'Missing item name',
            'cac:Price/cbc:PriceAmount' => 'Missing price amount',
            'cac:Item/cac:ClassifiedTaxCategory/cbc:ID' => 'Missing tax category',
        ], $line);

        return array_map(fn (string $error) => "Line {$lineNum}: {$error}", $errors);
    }

    /**
     * Validate totals.
     */
    protected function validateTotals(DOMDocument $doc): array
    {
        $xpath = $this->createXPath($doc);

        $errors = $this->missingElementErrors($xpath, [
            '//cac:TaxTotal/cbc:TaxAmount' => 'Missing tax total amount',
            '//cac:LegalMonetaryTotal/cbc:LineExtensionAmount' => 'Missing line extension amount in legal monetary total',
            '//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' => 'Missing tax exclusive amount in legal monetary total',
            '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount' => 'Missing tax inclusive amount in legal monetary total',
            '//cac:LegalMonetaryTotal/cbc:PayableAmount' => 'Missing payable amount in legal monetary total',
        ]);

        return array_merge($errors, $this->taxInclusiveMismatchErrors($xpath));
    }

    /**
     * Validate amounts match: the tax inclusive amount is the line extension amount plus the tax.
     *
     * @return list<string>
     */
    protected function taxInclusiveMismatchErrors(\DOMXPath $xpath): array
    {
        $taxTotal = $xpath->query('//cac:TaxTotal/cbc:TaxAmount');
        $lineExt = $xpath->query('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount');
        $taxIncl = $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount');

        if (! $lineExt || $lineExt->length === 0 || ! $taxTotal || $taxTotal->length === 0 || ! $taxIncl || $taxIncl->length === 0) {
            return [];
        }

        $lineExtValue = (float) $lineExt->item(0)->nodeValue;
        $taxValue = (float) $taxTotal->item(0)->nodeValue;
        $taxInclValue = (float) $taxIncl->item(0)->nodeValue;

        $expected = round($lineExtValue + $taxValue, 2);
        if (abs($expected - $taxInclValue) > 0.01) {
            return ["Tax inclusive amount ({$taxInclValue}) does not match line extension ({$lineExtValue}) + tax ({$taxValue})"];
        }

        return [];
    }

    /**
     * The messages of the required elements the XPath finds no node for, looked up from the context node when one is given.
     *
     * @param  array<string, string>  $requiredElements  XPath => the message when it finds nothing
     * @return list<string>
     */
    protected function missingElementErrors(\DOMXPath $xpath, array $requiredElements, ?\DOMNode $context = null): array
    {
        $errors = [];

        foreach ($requiredElements as $path => $message) {
            $nodes = $xpath->query($path, $context);
            if (! $nodes || $nodes->length === 0) {
                $errors[] = $message;
            }
        }

        return $errors;
    }

    /**
     * Create XPath with namespaces.
     */
    protected function createXPath(DOMDocument $doc): \DOMXPath
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');

        return $xpath;
    }

    /**
     * Get validation errors without throwing exception.
     */
    public function getErrors(string $xml): array
    {
        try {
            $this->validate($xml);

            return [];
        } catch (ValidationException $e) {
            return $e->getErrors();
        }
    }
}
