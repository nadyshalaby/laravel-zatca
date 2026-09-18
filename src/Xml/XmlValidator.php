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
        $errors = [];
        $xpath = $this->createXPath($doc);

        $requiredElements = [
            ['path' => '//cbc:ID', 'name' => 'Invoice ID'],
            ['path' => '//cbc:UUID', 'name' => 'UUID'],
            ['path' => '//cbc:IssueDate', 'name' => 'Issue Date'],
            ['path' => '//cbc:InvoiceTypeCode', 'name' => 'Invoice Type Code'],
            ['path' => '//cbc:DocumentCurrencyCode', 'name' => 'Document Currency Code'],
            ['path' => '//cac:AccountingSupplierParty', 'name' => 'Supplier Party'],
            ['path' => '//cac:TaxTotal', 'name' => 'Tax Total'],
            ['path' => '//cac:LegalMonetaryTotal', 'name' => 'Legal Monetary Total'],
            ['path' => '//cac:InvoiceLine', 'name' => 'Invoice Line'],
        ];

        foreach ($requiredElements as $element) {
            $nodes = $xpath->query($element['path']);
            if (! $nodes || $nodes->length === 0) {
                $errors[] = "Missing required element: {$element['name']}";
            }
        }

        // Validate ICV and PIH references
        $icvNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='ICV']");
        if (! $icvNodes || $icvNodes->length === 0) {
            $errors[] = 'Missing ICV (Invoice Counter Value) reference';
        }

        $pihNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']");
        if (! $pihNodes || $pihNodes->length === 0) {
            $errors[] = 'Missing PIH (Previous Invoice Hash) reference';
        }

        return $errors;
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
            $lineNum = $index + 1;

            // Line ID
            $id = $xpath->query('cbc:ID', $line);
            if (! $id || $id->length === 0) {
                $errors[] = "Line {$lineNum}: Missing line ID";
            }

            // Quantity
            $qty = $xpath->query('cbc:InvoicedQuantity', $line);
            if (! $qty || $qty->length === 0) {
                $errors[] = "Line {$lineNum}: Missing quantity";
            }

            // Line extension amount
            $lineExt = $xpath->query('cbc:LineExtensionAmount', $line);
            if (! $lineExt || $lineExt->length === 0) {
                $errors[] = "Line {$lineNum}: Missing line extension amount";
            }

            // Item name
            $itemName = $xpath->query('cac:Item/cbc:Name', $line);
            if (! $itemName || $itemName->length === 0) {
                $errors[] = "Line {$lineNum}: Missing item name";
            }

            // Price
            $price = $xpath->query('cac:Price/cbc:PriceAmount', $line);
            if (! $price || $price->length === 0) {
                $errors[] = "Line {$lineNum}: Missing price amount";
            }

            // Tax category
            $taxCat = $xpath->query('cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $line);
            if (! $taxCat || $taxCat->length === 0) {
                $errors[] = "Line {$lineNum}: Missing tax category";
            }
        }

        return $errors;
    }

    /**
     * Validate totals.
     */
    protected function validateTotals(DOMDocument $doc): array
    {
        $errors = [];
        $xpath = $this->createXPath($doc);

        // Tax total
        $taxTotal = $xpath->query('//cac:TaxTotal/cbc:TaxAmount');
        if (! $taxTotal || $taxTotal->length === 0) {
            $errors[] = 'Missing tax total amount';
        }

        // Legal monetary total
        $lineExt = $xpath->query('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount');
        if (! $lineExt || $lineExt->length === 0) {
            $errors[] = 'Missing line extension amount in legal monetary total';
        }

        $taxExcl = $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount');
        if (! $taxExcl || $taxExcl->length === 0) {
            $errors[] = 'Missing tax exclusive amount in legal monetary total';
        }

        $taxIncl = $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount');
        if (! $taxIncl || $taxIncl->length === 0) {
            $errors[] = 'Missing tax inclusive amount in legal monetary total';
        }

        $payable = $xpath->query('//cac:LegalMonetaryTotal/cbc:PayableAmount');
        if (! $payable || $payable->length === 0) {
            $errors[] = 'Missing payable amount in legal monetary total';
        }

        // Validate amounts match
        if ($lineExt && $lineExt->length > 0 && $taxTotal && $taxTotal->length > 0 && $taxIncl && $taxIncl->length > 0) {
            $lineExtValue = (float) $lineExt->item(0)->nodeValue;
            $taxValue = (float) $taxTotal->item(0)->nodeValue;
            $taxInclValue = (float) $taxIncl->item(0)->nodeValue;

            $expected = round($lineExtValue + $taxValue, 2);
            if (abs($expected - $taxInclValue) > 0.01) {
                $errors[] = "Tax inclusive amount ({$taxInclValue}) does not match line extension ({$lineExtValue}) + tax ({$taxValue})";
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
