<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\InvoiceInterface;
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

    protected DOMDocument $doc;

    protected UblElements $elements;

    /**
     * Generate UBL 2.1 XML for an invoice.
     */
    public function generate(InvoiceInterface $invoice): string
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $this->elements = new UblElements($this->doc);

        // Create root element
        $root = $this->createInvoiceRoot($invoice);
        $this->doc->appendChild($root);

        // Add UBL extensions (required for signature)
        $this->addUblExtensions($root);

        $this->addDocumentHeader($root, $invoice);
        $this->addDocumentReferences($root, $invoice);

        // Add seller (AccountingSupplierParty)
        $parties = new UblPartyWriter($this->elements);
        $parties->addSupplierParty($root, $invoice->getSeller());

        // Add buyer (AccountingCustomerParty) - Required even for B2C simplified invoices
        $parties->addCustomerParty($root, $invoice);

        // Add delivery information - Always required
        $this->addDelivery($root, $invoice);

        // Add payment means - Always include for compliance
        $this->addPaymentMeans($root, $invoice);

        // Add allowance/charge (document level), tax total and legal monetary total
        (new UblTotalsWriter($this->elements))->addTotals($root, $invoice);

        // Add invoice lines
        (new UblLineWriter($this->elements))->addInvoiceLines($root, $invoice);

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
     *
     * Note: The signature namespaces (sig, sac, sbc, ds) are only used within
     * UBLDocumentSignatures and must be declared there since they're not on root.
     */
    protected function addUblExtensions(DOMElement $parent): void
    {
        // Build structure: UBLExtensions > UBLExtension > ExtensionURI + ExtensionContent
        // These use ext: namespace which is declared on root
        $extensions = $this->elements->createElement('ext:UBLExtensions');
        $parent->appendChild($extensions);

        $extension = $this->elements->createElement('ext:UBLExtension');
        $extensions->appendChild($extension);

        $extensionUri = $this->elements->createElement('ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $extension->appendChild($extensionUri);

        $extensionContent = $this->elements->createElement('ext:ExtensionContent');
        $extension->appendChild($extensionContent);

        // Signature container - must declare sig/sac/sbc/ds namespaces here
        $sigContainer = $this->doc->createElementNS(self::NS_SIG, 'sig:UBLDocumentSignatures');
        $sigContainer->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::NS_SAC);
        $sigContainer->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sbc', self::NS_SBC);
        $sigContainer->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::NS_DS);
        $extensionContent->appendChild($sigContainer);

        // SignatureInformation element
        $signatureInfo = $this->doc->createElement('sac:SignatureInformation');
        $sigContainer->appendChild($signatureInfo);

        // ID element (uses cbc namespace from root)
        $signatureId = $this->elements->createElement('cbc:ID', 'urn:oasis:names:specification:ubl:signature:1');
        $signatureInfo->appendChild($signatureId);

        // ReferencedSignatureID
        $referencedSignatureId = $this->doc->createElement('sbc:ReferencedSignatureID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $signatureInfo->appendChild($referencedSignatureId);

        // Signature placeholder (will be replaced during signing)
        $signaturePlaceholder = $this->doc->createElement('ds:Signature');
        $signaturePlaceholder->setAttribute('Id', 'signature');
        $signatureInfo->appendChild($signaturePlaceholder);
    }

    /**
     * Add the profile ID, the invoice identification and type code, the credit or debit note reason, and the currencies.
     */
    protected function addDocumentHeader(DOMElement $root, InvoiceInterface $invoice): void
    {
        // Add profile ID
        $this->elements->addElement($root, 'cbc:ProfileID', 'reporting:1.0');

        // Add invoice identification
        $this->elements->addElement($root, 'cbc:ID', $invoice->getInvoiceNumber());
        $this->elements->addElement($root, 'cbc:UUID', $invoice->getUuid());
        $this->elements->addElement($root, 'cbc:IssueDate', $invoice->getIssueDate()->format('Y-m-d'));
        $this->elements->addElement($root, 'cbc:IssueTime', $invoice->getIssueDate()->format('H:i:s'));

        // Add invoice type code
        $this->addInvoiceTypeCode($root, $invoice);

        // Add instruction note for credit/debit notes (KSA-10 reason)
        // Per UBL 2.1 schema, Note must come AFTER InvoiceTypeCode and BEFORE DocumentCurrencyCode
        if (($invoice->isCreditNote() || $invoice->isDebitNote()) && $invoice->getInstructionNote()) {
            $this->elements->addElement($root, 'cbc:Note', $invoice->getInstructionNote());
        }

        // Add document currency
        $this->elements->addElement($root, 'cbc:DocumentCurrencyCode', $invoice->getCurrency());

        // Add tax currency (same as document currency for KSA)
        $this->elements->addElement($root, 'cbc:TaxCurrencyCode', $invoice->getCurrency());
    }

    /**
     * Add the billing reference of a credit or debit note, the ICV, PIH and QR references, and the signature placeholder.
     */
    protected function addDocumentReferences(DOMElement $root, InvoiceInterface $invoice): void
    {
        // Add billing reference for credit/debit notes (must be before AdditionalDocumentReference)
        if ($invoice->isCreditNote() || $invoice->isDebitNote()) {
            $this->addBillingReference($root, $invoice);
        }

        // Add invoice counter value (ICV)
        $this->addUuidReference($root, 'ICV', (string) $invoice->getIcv());

        // Add previous invoice hash (PIH)
        $this->addAttachmentReference($root, 'PIH', $invoice->getPreviousInvoiceHash());

        // Add QR code placeholder (will be replaced after signing)
        $this->addAttachmentReference($root, 'QR', '');

        // Add signature placeholder
        $this->addSignaturePlaceholder($root);
    }

    /**
     * Add invoice type code.
     */
    protected function addInvoiceTypeCode(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $typeCode = $this->elements->createElement('cbc:InvoiceTypeCode', $invoice->getTypeCode());
        $typeCode->setAttribute('name', $invoice->getSubTypeCode());
        $parent->appendChild($typeCode);
    }

    /**
     * Add an additional document reference that carries its value as a cbc:UUID.
     */
    protected function addUuidReference(DOMElement $parent, string $id, string $value): void
    {
        $ref = $this->elements->createElement('cac:AdditionalDocumentReference');

        $this->elements->addElement($ref, 'cbc:ID', $id);
        $this->elements->addElement($ref, 'cbc:UUID', $value);

        $parent->appendChild($ref);
    }

    /**
     * Add an additional document reference that carries its value as an embedded text attachment.
     */
    protected function addAttachmentReference(DOMElement $parent, string $id, string $value): void
    {
        $ref = $this->elements->createElement('cac:AdditionalDocumentReference');

        $this->elements->addElement($ref, 'cbc:ID', $id);

        $attachment = $this->elements->createElement('cac:Attachment');
        $embedded = $this->elements->createElement('cbc:EmbeddedDocumentBinaryObject', $value);
        $embedded->setAttribute('mimeCode', 'text/plain');
        $attachment->appendChild($embedded);
        $ref->appendChild($attachment);

        $parent->appendChild($ref);
    }

    /**
     * Add billing reference for credit/debit notes.
     */
    protected function addBillingReference(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $billingRef = $this->elements->createElement('cac:BillingReference');
        $invoiceDocRef = $this->elements->createElement('cac:InvoiceDocumentReference');
        $this->elements->addElement($invoiceDocRef, 'cbc:ID', $invoice->getOriginalInvoiceReference());
        $billingRef->appendChild($invoiceDocRef);

        $parent->appendChild($billingRef);
    }

    /**
     * Add signature placeholder.
     *
     * ZATCA requires specific values for KSA-15 (cac:Signature element):
     * - ID must be: urn:oasis:names:specification:ubl:signature:Invoice
     * - SignatureMethod must be: urn:oasis:names:specification:ubl:dsig:enveloped:xades
     *
     * Note: The UBLExtensions/SignatureInformation uses ID = urn:oasis:names:specification:ubl:signature:1
     */
    protected function addSignaturePlaceholder(DOMElement $parent): void
    {
        $signature = $this->elements->createElement('cac:Signature');
        $this->elements->addElement($signature, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $this->elements->addElement($signature, 'cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $parent->appendChild($signature);
    }

    /**
     * Add delivery information.
     */
    protected function addDelivery(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $delivery = $this->elements->createElement('cac:Delivery');

        // Use supply date if provided, otherwise use issue date
        $deliveryDate = $invoice->getSupplyDate() ?? $invoice->getIssueDate();
        $this->elements->addElement($delivery, 'cbc:ActualDeliveryDate', $deliveryDate->format('Y-m-d'));

        $parent->appendChild($delivery);
    }

    /**
     * Add payment means.
     */
    protected function addPaymentMeans(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $paymentMeans = $this->elements->createElement('cac:PaymentMeans');

        // Use payment method if provided, otherwise default to cash (10)
        $paymentCode = $invoice->getPaymentMethod() ?? '10';
        $this->elements->addElement($paymentMeans, 'cbc:PaymentMeansCode', $paymentCode);

        if ($invoice->getPaymentTerms()) {
            $this->elements->addElement($paymentMeans, 'cbc:InstructionNote', $invoice->getPaymentTerms());
        }

        $parent->appendChild($paymentMeans);
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
