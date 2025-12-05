<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Contracts\LineItemInterface;
use Corecave\Zatca\Enums\VatCategory;
use DOMDocument;
use DOMElement;

class UblGenerator
{
    /**
     * XML Namespaces.
     */
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    private const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    private const NS_SBC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    protected DOMDocument $doc;

    /**
     * Generate UBL 2.1 XML for an invoice.
     */
    public function generate(InvoiceInterface $invoice): string
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        // Create root element
        $root = $this->createInvoiceRoot($invoice);
        $this->doc->appendChild($root);

        // Add UBL extensions (required for signature)
        $this->addUblExtensions($root);

        // Add profile ID
        $this->addElement($root, 'cbc:ProfileID', 'reporting:1.0');

        // Add invoice identification
        $this->addElement($root, 'cbc:ID', $invoice->getInvoiceNumber());
        $this->addElement($root, 'cbc:UUID', $invoice->getUuid());
        $this->addElement($root, 'cbc:IssueDate', $invoice->getIssueDate()->format('Y-m-d'));
        $this->addElement($root, 'cbc:IssueTime', $invoice->getIssueDate()->format('H:i:s'));

        // Add invoice type code
        $this->addInvoiceTypeCode($root, $invoice);

        // Add document currency
        $this->addElement($root, 'cbc:DocumentCurrencyCode', $invoice->getCurrency());

        // Add tax currency (same as document currency for KSA)
        $this->addElement($root, 'cbc:TaxCurrencyCode', $invoice->getCurrency());

        // Add invoice counter value (ICV)
        $this->addAdditionalDocumentReference($root, 'ICV', (string) $invoice->getIcv());

        // Add previous invoice hash (PIH)
        $this->addAdditionalDocumentReference($root, 'PIH', $invoice->getPreviousInvoiceHash(), true);

        // Add QR code placeholder (will be replaced after signing)
        $this->addAdditionalDocumentReference($root, 'QR', '', true);

        // Add billing reference for credit/debit notes
        if ($invoice->isCreditNote() || $invoice->isDebitNote()) {
            $this->addBillingReference($root, $invoice);
        }

        // Add signature placeholder
        $this->addSignaturePlaceholder($root);

        // Add seller (AccountingSupplierParty)
        $this->addSupplierParty($root, $invoice->getSeller());

        // Add buyer (AccountingCustomerParty)
        if ($invoice->getBuyer()) {
            $this->addCustomerParty($root, $invoice->getBuyer());
        }

        // Add delivery information if supply date is different
        if ($invoice->getSupplyDate()) {
            $this->addDelivery($root, $invoice);
        }

        // Add payment means
        if ($invoice->getPaymentMethod()) {
            $this->addPaymentMeans($root, $invoice);
        }

        // Add allowance/charge (document level)
        if ($invoice->getTotalDiscount() > 0) {
            $this->addAllowanceCharge($root, $invoice);
        }

        // Add tax total
        $this->addTaxTotal($root, $invoice);

        // Add legal monetary total
        $this->addLegalMonetaryTotal($root, $invoice);

        // Add invoice lines
        foreach ($invoice->getLineItems() as $lineItem) {
            $this->addInvoiceLine($root, $lineItem, $invoice->getCurrency());
        }

        return $this->doc->saveXML();
    }

    /**
     * Create the invoice root element with all namespaces.
     */
    protected function createInvoiceRoot(InvoiceInterface $invoice): DOMElement
    {
        $root = $this->doc->createElementNS(self::NS_INVOICE, 'Invoice');

        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::NS_EXT);

        return $root;
    }

    /**
     * Add UBL extensions for signature.
     */
    protected function addUblExtensions(DOMElement $parent): void
    {
        $extensions = $this->doc->createElementNS(self::NS_EXT, 'ext:UBLExtensions');

        $extension = $this->doc->createElementNS(self::NS_EXT, 'ext:UBLExtension');
        $extensionUri = $this->doc->createElementNS(self::NS_EXT, 'ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $extension->appendChild($extensionUri);

        $extensionContent = $this->doc->createElementNS(self::NS_EXT, 'ext:ExtensionContent');

        // Signature container placeholder
        $sigContainer = $this->doc->createElementNS(self::NS_SIG, 'sig:UBLDocumentSignatures');
        $sigContainer->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sig', self::NS_SIG);
        $sigContainer->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::NS_SAC);
        $sigContainer->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sbc', self::NS_SBC);

        $signatureInfo = $this->doc->createElementNS(self::NS_SAC, 'sac:SignatureInformation');

        $signatureId = $this->doc->createElementNS(self::NS_CBC, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:1');
        $signatureInfo->appendChild($signatureId);

        $referencedSignatureId = $this->doc->createElementNS(self::NS_SBC, 'sbc:ReferencedSignatureID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $signatureInfo->appendChild($referencedSignatureId);

        // Signature placeholder (will be replaced during signing)
        $signaturePlaceholder = $this->doc->createElementNS(self::NS_DS, 'ds:Signature');
        $signaturePlaceholder->setAttribute('Id', 'signature');
        $signatureInfo->appendChild($signaturePlaceholder);

        $sigContainer->appendChild($signatureInfo);
        $extensionContent->appendChild($sigContainer);
        $extension->appendChild($extensionContent);
        $extensions->appendChild($extension);

        $parent->appendChild($extensions);
    }

    /**
     * Add invoice type code.
     */
    protected function addInvoiceTypeCode(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $typeCode = $this->doc->createElementNS(self::NS_CBC, 'cbc:InvoiceTypeCode', $invoice->getTypeCode());
        $typeCode->setAttribute('name', $invoice->getSubTypeCode());
        $parent->appendChild($typeCode);
    }

    /**
     * Add additional document reference.
     */
    protected function addAdditionalDocumentReference(DOMElement $parent, string $id, string $value, bool $isAttachment = false): void
    {
        $ref = $this->doc->createElementNS(self::NS_CAC, 'cac:AdditionalDocumentReference');

        $this->addElement($ref, 'cbc:ID', $id);

        if ($isAttachment) {
            $attachment = $this->doc->createElementNS(self::NS_CAC, 'cac:Attachment');
            $embedded = $this->doc->createElementNS(self::NS_CBC, 'cbc:EmbeddedDocumentBinaryObject', $value);
            $embedded->setAttribute('mimeCode', 'text/plain');
            $attachment->appendChild($embedded);
            $ref->appendChild($attachment);
        } else {
            $this->addElement($ref, 'cbc:UUID', $value);
        }

        $parent->appendChild($ref);
    }

    /**
     * Add billing reference for credit/debit notes.
     */
    protected function addBillingReference(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $billingRef = $this->doc->createElementNS(self::NS_CAC, 'cac:BillingReference');
        $invoiceDocRef = $this->doc->createElementNS(self::NS_CAC, 'cac:InvoiceDocumentReference');
        $this->addElement($invoiceDocRef, 'cbc:ID', $invoice->getOriginalInvoiceReference());
        $billingRef->appendChild($invoiceDocRef);

        $parent->appendChild($billingRef);

        // Add instruction note (reason)
        if ($invoice->getInstructionNote()) {
            // Find where to insert (after BillingReference)
            $this->addElement($parent, 'cbc:Note', $invoice->getInstructionNote());
        }
    }

    /**
     * Add signature placeholder.
     */
    protected function addSignaturePlaceholder(DOMElement $parent): void
    {
        $signature = $this->doc->createElementNS(self::NS_CAC, 'cac:Signature');
        $this->addElement($signature, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $this->addElement($signature, 'cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $parent->appendChild($signature);
    }

    /**
     * Add supplier party (seller).
     */
    protected function addSupplierParty(DOMElement $parent, array $seller): void
    {
        $supplier = $this->doc->createElementNS(self::NS_CAC, 'cac:AccountingSupplierParty');
        $party = $this->doc->createElementNS(self::NS_CAC, 'cac:Party');

        // Party identification
        $partyId = $this->doc->createElementNS(self::NS_CAC, 'cac:PartyIdentification');
        $id = $this->doc->createElementNS(self::NS_CBC, 'cbc:ID', $seller['registration_number'] ?? $seller['vat_number']);
        $id->setAttribute('schemeID', 'CRN');
        $partyId->appendChild($id);
        $party->appendChild($partyId);

        // Postal address
        $this->addPostalAddress($party, $seller['address'] ?? []);

        // Party tax scheme
        $taxScheme = $this->doc->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
        $this->addElement($taxScheme, 'cbc:CompanyID', $seller['vat_number']);
        $taxSchemeInfo = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $this->addElement($taxSchemeInfo, 'cbc:ID', 'VAT');
        $taxScheme->appendChild($taxSchemeInfo);
        $party->appendChild($taxScheme);

        // Party legal entity
        $legalEntity = $this->doc->createElementNS(self::NS_CAC, 'cac:PartyLegalEntity');
        $this->addElement($legalEntity, 'cbc:RegistrationName', $seller['name_ar'] ?? $seller['name']);
        $party->appendChild($legalEntity);

        $supplier->appendChild($party);
        $parent->appendChild($supplier);
    }

    /**
     * Add customer party (buyer).
     */
    protected function addCustomerParty(DOMElement $parent, array $buyer): void
    {
        $customer = $this->doc->createElementNS(self::NS_CAC, 'cac:AccountingCustomerParty');
        $party = $this->doc->createElementNS(self::NS_CAC, 'cac:Party');

        // Party identification (if has registration number)
        if (! empty($buyer['registration_number'])) {
            $partyId = $this->doc->createElementNS(self::NS_CAC, 'cac:PartyIdentification');
            $id = $this->doc->createElementNS(self::NS_CBC, 'cbc:ID', $buyer['registration_number']);
            $id->setAttribute('schemeID', 'CRN');
            $partyId->appendChild($id);
            $party->appendChild($partyId);
        }

        // Postal address
        if (! empty($buyer['address'])) {
            $this->addPostalAddress($party, $buyer['address']);
        }

        // Party tax scheme (if has VAT number)
        if (! empty($buyer['vat_number'])) {
            $taxScheme = $this->doc->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
            $this->addElement($taxScheme, 'cbc:CompanyID', $buyer['vat_number']);
            $taxSchemeInfo = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
            $this->addElement($taxSchemeInfo, 'cbc:ID', 'VAT');
            $taxScheme->appendChild($taxSchemeInfo);
            $party->appendChild($taxScheme);
        }

        // Party legal entity
        $legalEntity = $this->doc->createElementNS(self::NS_CAC, 'cac:PartyLegalEntity');
        $this->addElement($legalEntity, 'cbc:RegistrationName', $buyer['name']);
        $party->appendChild($legalEntity);

        $customer->appendChild($party);
        $parent->appendChild($customer);
    }

    /**
     * Add postal address.
     */
    protected function addPostalAddress(DOMElement $party, array $address): void
    {
        $postal = $this->doc->createElementNS(self::NS_CAC, 'cac:PostalAddress');

        if (! empty($address['street'])) {
            $this->addElement($postal, 'cbc:StreetName', $address['street']);
        }

        if (! empty($address['building'])) {
            $this->addElement($postal, 'cbc:BuildingNumber', $address['building']);
        }

        if (! empty($address['plot'])) {
            $this->addElement($postal, 'cbc:PlotIdentification', $address['plot']);
        }

        if (! empty($address['city_subdivision'])) {
            $this->addElement($postal, 'cbc:CitySubdivisionName', $address['city_subdivision']);
        }

        if (! empty($address['city'])) {
            $this->addElement($postal, 'cbc:CityName', $address['city']);
        }

        if (! empty($address['postal_code'])) {
            $this->addElement($postal, 'cbc:PostalZone', $address['postal_code']);
        }

        if (! empty($address['district'])) {
            $this->addElement($postal, 'cbc:CountrySubentity', $address['district']);
        }

        // Country
        $country = $this->doc->createElementNS(self::NS_CAC, 'cac:Country');
        $this->addElement($country, 'cbc:IdentificationCode', $address['country'] ?? 'SA');
        $postal->appendChild($country);

        $party->appendChild($postal);
    }

    /**
     * Add delivery information.
     */
    protected function addDelivery(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $delivery = $this->doc->createElementNS(self::NS_CAC, 'cac:Delivery');
        $this->addElement($delivery, 'cbc:ActualDeliveryDate', $invoice->getSupplyDate()->format('Y-m-d'));
        $parent->appendChild($delivery);
    }

    /**
     * Add payment means.
     */
    protected function addPaymentMeans(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $paymentMeans = $this->doc->createElementNS(self::NS_CAC, 'cac:PaymentMeans');
        $this->addElement($paymentMeans, 'cbc:PaymentMeansCode', $invoice->getPaymentMethod());

        if ($invoice->getPaymentTerms()) {
            $this->addElement($paymentMeans, 'cbc:InstructionNote', $invoice->getPaymentTerms());
        }

        $parent->appendChild($paymentMeans);
    }

    /**
     * Add allowance/charge.
     */
    protected function addAllowanceCharge(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $allowance = $this->doc->createElementNS(self::NS_CAC, 'cac:AllowanceCharge');
        $this->addElement($allowance, 'cbc:ChargeIndicator', 'false');
        $this->addElement($allowance, 'cbc:AllowanceChargeReason', 'Discount');

        $amount = $this->doc->createElementNS(self::NS_CBC, 'cbc:Amount', $this->formatAmount($invoice->getTotalDiscount()));
        $amount->setAttribute('currencyID', $invoice->getCurrency());
        $allowance->appendChild($amount);

        $parent->appendChild($allowance);
    }

    /**
     * Add tax total.
     */
    protected function addTaxTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $taxTotal = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxTotal');

        // Total tax amount
        $taxAmount = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxAmount', $this->formatAmount($invoice->getTotalVat()));
        $taxAmount->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal->appendChild($taxAmount);

        // Tax subtotals by category
        foreach ($invoice->getVatBreakdown() as $breakdown) {
            $this->addTaxSubtotal($taxTotal, $breakdown, $invoice->getCurrency());
        }

        $parent->appendChild($taxTotal);

        // Add second TaxTotal for tax currency (required by ZATCA)
        $taxTotal2 = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxTotal');
        $taxAmount2 = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxAmount', $this->formatAmount($invoice->getTotalVat()));
        $taxAmount2->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal2->appendChild($taxAmount2);
        $parent->appendChild($taxTotal2);
    }

    /**
     * Add tax subtotal.
     */
    protected function addTaxSubtotal(DOMElement $taxTotal, array $breakdown, string $currency): void
    {
        $subtotal = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxSubtotal');

        // Taxable amount
        $taxableAmount = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxableAmount', $this->formatAmount($breakdown['taxable_amount']));
        $taxableAmount->setAttribute('currencyID', $currency);
        $subtotal->appendChild($taxableAmount);

        // Tax amount
        $taxAmount = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxAmount', $this->formatAmount($breakdown['tax_amount']));
        $taxAmount->setAttribute('currencyID', $currency);
        $subtotal->appendChild($taxAmount);

        // Tax category
        $taxCategory = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxCategory');
        $this->addElement($taxCategory, 'cbc:ID', $breakdown['category']->value);
        $this->addElement($taxCategory, 'cbc:Percent', $this->formatAmount($breakdown['rate']));

        // Add exemption reason if applicable
        if ($breakdown['exemption_reason'] && $breakdown['category'] !== VatCategory::STANDARD) {
            $this->addElement($taxCategory, 'cbc:TaxExemptionReasonCode', $breakdown['exemption_reason']->value);
            $this->addElement($taxCategory, 'cbc:TaxExemptionReason', $breakdown['exemption_reason']->description());
        }

        $taxScheme = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $this->addElement($taxScheme, 'cbc:ID', 'VAT');
        $taxCategory->appendChild($taxScheme);

        $subtotal->appendChild($taxCategory);
        $taxTotal->appendChild($subtotal);
    }

    /**
     * Add legal monetary total.
     */
    protected function addLegalMonetaryTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $monetary = $this->doc->createElementNS(self::NS_CAC, 'cac:LegalMonetaryTotal');

        // Line extension amount (sum of line subtotals)
        $lineExt = $this->doc->createElementNS(self::NS_CBC, 'cbc:LineExtensionAmount', $this->formatAmount($invoice->getSubtotal()));
        $lineExt->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($lineExt);

        // Tax exclusive amount
        $taxExcl = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxExclusiveAmount', $this->formatAmount($invoice->getSubtotal()));
        $taxExcl->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($taxExcl);

        // Tax inclusive amount
        $taxIncl = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxInclusiveAmount', $this->formatAmount($invoice->getTotalWithVat()));
        $taxIncl->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($taxIncl);

        // Allowance total
        if ($invoice->getTotalDiscount() > 0) {
            $allowance = $this->doc->createElementNS(self::NS_CBC, 'cbc:AllowanceTotalAmount', $this->formatAmount($invoice->getTotalDiscount()));
            $allowance->setAttribute('currencyID', $invoice->getCurrency());
            $monetary->appendChild($allowance);
        }

        // Payable amount
        $payable = $this->doc->createElementNS(self::NS_CBC, 'cbc:PayableAmount', $this->formatAmount($invoice->getTotalWithVat()));
        $payable->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($payable);

        $parent->appendChild($monetary);
    }

    /**
     * Add invoice line.
     */
    protected function addInvoiceLine(DOMElement $parent, LineItemInterface $item, string $currency): void
    {
        $line = $this->doc->createElementNS(self::NS_CAC, 'cac:InvoiceLine');

        // Line ID
        $this->addElement($line, 'cbc:ID', (string) $item->getId());

        // Invoiced quantity
        $quantity = $this->doc->createElementNS(self::NS_CBC, 'cbc:InvoicedQuantity', $this->formatQuantity($item->getQuantity()));
        $quantity->setAttribute('unitCode', $item->getUnitCode());
        $line->appendChild($quantity);

        // Line extension amount
        $lineExt = $this->doc->createElementNS(self::NS_CBC, 'cbc:LineExtensionAmount', $this->formatAmount($item->getSubtotal()));
        $lineExt->setAttribute('currencyID', $currency);
        $line->appendChild($lineExt);

        // Tax total for line
        $taxTotal = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxTotal');
        $taxAmount = $this->doc->createElementNS(self::NS_CBC, 'cbc:TaxAmount', $this->formatAmount($item->getVatAmount()));
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);

        // Rounding amount (required)
        $roundingAmount = $this->doc->createElementNS(self::NS_CBC, 'cbc:RoundingAmount', $this->formatAmount($item->getTotal()));
        $roundingAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($roundingAmount);

        $line->appendChild($taxTotal);

        // Item
        $itemEl = $this->doc->createElementNS(self::NS_CAC, 'cac:Item');
        $this->addElement($itemEl, 'cbc:Name', $item->getName());

        // Classified tax category
        $taxCategory = $this->doc->createElementNS(self::NS_CAC, 'cac:ClassifiedTaxCategory');
        $this->addElement($taxCategory, 'cbc:ID', $item->getVatCategory()->value);
        $this->addElement($taxCategory, 'cbc:Percent', $this->formatAmount($item->getVatRate()));

        $taxScheme = $this->doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $this->addElement($taxScheme, 'cbc:ID', 'VAT');
        $taxCategory->appendChild($taxScheme);

        $itemEl->appendChild($taxCategory);
        $line->appendChild($itemEl);

        // Price
        $price = $this->doc->createElementNS(self::NS_CAC, 'cac:Price');
        $priceAmount = $this->doc->createElementNS(self::NS_CBC, 'cbc:PriceAmount', $this->formatAmount($item->getUnitPrice()));
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);
        $line->appendChild($price);

        $parent->appendChild($line);
    }

    /**
     * Add a simple element.
     */
    protected function addElement(DOMElement $parent, string $name, string $value): DOMElement
    {
        $parts = explode(':', $name);
        $prefix = $parts[0];
        $localName = $parts[1];

        $ns = match ($prefix) {
            'cbc' => self::NS_CBC,
            'cac' => self::NS_CAC,
            'ext' => self::NS_EXT,
            default => null,
        };

        $element = $ns
            ? $this->doc->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1))
            : $this->doc->createElement($name, htmlspecialchars($value, ENT_XML1));

        $parent->appendChild($element);

        return $element;
    }

    /**
     * Format amount for XML.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format quantity for XML.
     */
    protected function formatQuantity(float $quantity): string
    {
        // Use up to 5 decimal places for quantity
        return rtrim(rtrim(number_format($quantity, 5, '.', ''), '0'), '.');
    }

    /**
     * Add QR code to signed XML.
     */
    public function addQrCode(string $xml, string $qrCode): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        // Find QR reference
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('cac', self::NS_CAC);
        $xpath->registerNamespace('cbc', self::NS_CBC);

        $qrNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        if ($qrNodes && $qrNodes->length > 0) {
            $qrNodes->item(0)->nodeValue = $qrCode;
        }

        return $doc->saveXML();
    }

    /**
     * Extract QR code from XML.
     */
    public function extractQrCode(string $xml): ?string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('cac', self::NS_CAC);
        $xpath->registerNamespace('cbc', self::NS_CBC);

        $qrNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        if ($qrNodes && $qrNodes->length > 0) {
            return $qrNodes->item(0)->nodeValue;
        }

        return null;
    }
}
