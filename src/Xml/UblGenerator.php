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

        // Add instruction note for credit/debit notes (KSA-10 reason)
        // Per UBL 2.1 schema, Note must come AFTER InvoiceTypeCode and BEFORE DocumentCurrencyCode
        if (($invoice->isCreditNote() || $invoice->isDebitNote()) && $invoice->getInstructionNote()) {
            $this->addElement($root, 'cbc:Note', $invoice->getInstructionNote());
        }

        // Add document currency
        $this->addElement($root, 'cbc:DocumentCurrencyCode', $invoice->getCurrency());

        // Add tax currency (same as document currency for KSA)
        $this->addElement($root, 'cbc:TaxCurrencyCode', $invoice->getCurrency());

        // Add billing reference for credit/debit notes (must be before AdditionalDocumentReference)
        if ($invoice->isCreditNote() || $invoice->isDebitNote()) {
            $this->addBillingReference($root, $invoice);
        }

        // Add invoice counter value (ICV)
        $this->addAdditionalDocumentReference($root, 'ICV', (string) $invoice->getIcv());

        // Add previous invoice hash (PIH)
        $this->addAdditionalDocumentReference($root, 'PIH', $invoice->getPreviousInvoiceHash(), true);

        // Add QR code placeholder (will be replaced after signing)
        $this->addAdditionalDocumentReference($root, 'QR', '', true);

        // Add signature placeholder
        $this->addSignaturePlaceholder($root);

        // Add seller (AccountingSupplierParty)
        $this->addSupplierParty($root, $invoice->getSeller());

        // Add buyer (AccountingCustomerParty) - Required even for B2C simplified invoices
        $this->addCustomerParty($root, $invoice->getBuyer(), $invoice->isSimplified());

        // Add delivery information - Always required
        $this->addDelivery($root, $invoice);

        // Add payment means - Always include for compliance
        $this->addPaymentMeans($root, $invoice);

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
     * Create an element in the correct namespace without redundant declarations.
     *
     * This method creates elements using the document's createElement method
     * which avoids the redundant namespace declaration issue that occurs
     * when using createElementNS before appending to a parent.
     */
    protected function createElement(string $qualifiedName, ?string $value = null): DOMElement
    {
        if ($value !== null) {
            $element = $this->doc->createElement($qualifiedName, htmlspecialchars($value, ENT_XML1));
        } else {
            $element = $this->doc->createElement($qualifiedName);
        }

        return $element;
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
        $extensions = $this->createElement('ext:UBLExtensions');
        $parent->appendChild($extensions);

        $extension = $this->createElement('ext:UBLExtension');
        $extensions->appendChild($extension);

        $extensionUri = $this->createElement('ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $extension->appendChild($extensionUri);

        $extensionContent = $this->createElement('ext:ExtensionContent');
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
        $signatureId = $this->createElement('cbc:ID', 'urn:oasis:names:specification:ubl:signature:1');
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
     * Add invoice type code.
     */
    protected function addInvoiceTypeCode(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $typeCode = $this->createElement('cbc:InvoiceTypeCode', $invoice->getTypeCode());
        $typeCode->setAttribute('name', $invoice->getSubTypeCode());
        $parent->appendChild($typeCode);
    }

    /**
     * Add additional document reference.
     */
    protected function addAdditionalDocumentReference(DOMElement $parent, string $id, string $value, bool $isAttachment = false): void
    {
        $ref = $this->createElement('cac:AdditionalDocumentReference');

        $this->addElement($ref, 'cbc:ID', $id);

        if ($isAttachment) {
            $attachment = $this->createElement('cac:Attachment');
            $embedded = $this->createElement('cbc:EmbeddedDocumentBinaryObject', $value);
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
        $billingRef = $this->createElement('cac:BillingReference');
        $invoiceDocRef = $this->createElement('cac:InvoiceDocumentReference');
        $this->addElement($invoiceDocRef, 'cbc:ID', $invoice->getOriginalInvoiceReference());
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
        $signature = $this->createElement('cac:Signature');
        $this->addElement($signature, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $this->addElement($signature, 'cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $parent->appendChild($signature);
    }

    /**
     * Add supplier party (seller).
     *
     * ZATCA BR-KSA-08 requires seller identification (BT-29) with valid schemeID.
     * Valid schemeID values: CRN (10 digits), MOM, MLS, SAG, OTH, 700 (10 digits starting with 7)
     *
     * BR-KSA-F-08: CRN must be exactly 10 digits
     * BR-KSA-F-09: 700 must be exactly 10 digits starting with 7
     *
     * BR-KSA-09: Seller address must contain street name (BT-35), building number (KSA-17),
     * postal code (BT-38), city (BT-37), district (KSA-3), country code (BT-40)
     *
     * BR-KSA-37: Building number must be 4 digits
     */
    protected function addSupplierParty(DOMElement $parent, array $seller): void
    {
        $supplier = $this->createElement('cac:AccountingSupplierParty');
        $party = $this->createElement('cac:Party');

        // Party identification (BT-29) - REQUIRED by ZATCA BR-KSA-08
        // Use registration_number if available, otherwise use CRN from VAT number (middle 10 digits)
        $partyId = $this->createElement('cac:PartyIdentification');
        if (! empty($seller['registration_number'])) {
            $regNumber = $seller['registration_number'];
            $schemeId = $seller['registration_scheme'] ?? $this->detectSchemeId($regNumber);
        } else {
            // Extract CRN from VAT number (VAT format: 3XXXXXXXXXX00003 - middle 10 digits are CRN)
            // Or use a provided CRN, or fallback to OTH scheme
            $vatNumber = $seller['vat_number'] ?? '';
            if (strlen($vatNumber) === 15 && preg_match('/^3(\d{10})\d{4}$/', $vatNumber, $matches)) {
                $regNumber = $matches[1];
                $schemeId = 'CRN';
            } else {
                // Fallback: use VAT number with OTH scheme
                $regNumber = $vatNumber;
                $schemeId = 'OTH';
            }
        }
        $id = $this->createElement('cbc:ID', $regNumber);
        $id->setAttribute('schemeID', $schemeId);
        $partyId->appendChild($id);
        $party->appendChild($partyId);

        // Postal address (BR-KSA-09 requires full address)
        $this->addPostalAddress($party, $seller['address'] ?? []);

        // Party tax scheme
        $taxScheme = $this->createElement('cac:PartyTaxScheme');
        $this->addElement($taxScheme, 'cbc:CompanyID', $seller['vat_number']);
        $taxSchemeInfo = $this->createElement('cac:TaxScheme');
        $this->addElement($taxSchemeInfo, 'cbc:ID', 'VAT');
        $taxScheme->appendChild($taxSchemeInfo);
        $party->appendChild($taxScheme);

        // Party legal entity
        $legalEntity = $this->createElement('cac:PartyLegalEntity');
        $this->addElement($legalEntity, 'cbc:RegistrationName', $seller['name_ar'] ?? $seller['name']);
        $party->appendChild($legalEntity);

        $supplier->appendChild($party);
        $parent->appendChild($supplier);
    }

    /**
     * Add customer party (buyer).
     *
     * For simplified (B2C) invoices, minimal buyer info is required.
     * For standard (B2B) invoices with Saudi buyer, full address is required:
     * - Street name (BT-50)
     * - Building number (KSA-18)
     * - Postal code (BT-53) - 5 digits
     * - City (BT-52)
     * - District (KSA-4)
     * - Country code (BT-55)
     */
    protected function addCustomerParty(DOMElement $parent, ?array $buyer, bool $isSimplified = false): void
    {
        $customer = $this->createElement('cac:AccountingCustomerParty');
        $party = $this->createElement('cac:Party');

        // For simplified invoices without buyer info, use minimal placeholder
        if ($isSimplified && empty($buyer)) {
            // Party identification with NAT scheme for individual customers
            $partyId = $this->createElement('cac:PartyIdentification');
            $id = $this->createElement('cbc:ID', '1000000000');
            $id->setAttribute('schemeID', 'NAT');
            $partyId->appendChild($id);
            $party->appendChild($partyId);

            // Minimal postal address with country only (allowed for B2C)
            $postal = $this->createElement('cac:PostalAddress');
            $country = $this->createElement('cac:Country');
            $this->addElement($country, 'cbc:IdentificationCode', 'SA');
            $postal->appendChild($country);
            $party->appendChild($postal);

            // Party tax scheme (required even for simplified)
            $taxScheme = $this->createElement('cac:PartyTaxScheme');
            $taxSchemeInfo = $this->createElement('cac:TaxScheme');
            $this->addElement($taxSchemeInfo, 'cbc:ID', 'VAT');
            $taxScheme->appendChild($taxSchemeInfo);
            $party->appendChild($taxScheme);

            // Party legal entity with placeholder name
            $legalEntity = $this->createElement('cac:PartyLegalEntity');
            $this->addElement($legalEntity, 'cbc:RegistrationName', 'Individual Customer');
            $party->appendChild($legalEntity);
        } else {
            // Use provided buyer info
            $buyer = $buyer ?? [];

            // Party identification (BT-46) - REQUIRED for all invoices
            // Use registration_number/national_id with appropriate schemeID
            $partyId = $this->createElement('cac:PartyIdentification');
            $buyerId = null;
            $schemeId = 'NAT'; // Default to NAT for individuals

            if (! empty($buyer['registration_number'])) {
                $buyerId = $buyer['registration_number'];
                $schemeId = $buyer['registration_scheme'] ?? $this->detectBuyerSchemeId($buyerId);
            } elseif (! empty($buyer['national_id'])) {
                $buyerId = $buyer['national_id'];
                $schemeId = $buyer['id_scheme'] ?? $this->detectBuyerSchemeId($buyerId);
            } elseif (! empty($buyer['vat_number'])) {
                // Use VAT number with TIN scheme for B2B
                $buyerId = $buyer['vat_number'];
                $schemeId = 'TIN';
            } elseif ($isSimplified) {
                // For simplified without any ID, use placeholder NAT
                $buyerId = '1000000000';
                $schemeId = 'NAT';
            } else {
                // For standard invoices, buyer ID is required - use placeholder if not provided
                // This will trigger BR-KSA-F-13 warning but at least the XML structure is valid
                $buyerId = '1000000000';
                $schemeId = 'NAT';
            }

            $id = $this->createElement('cbc:ID', $buyerId);
            $id->setAttribute('schemeID', $schemeId);
            $partyId->appendChild($id);
            $party->appendChild($partyId);

            // Postal address
            // For B2B (standard) with SA buyer, full address is required
            // For B2C (simplified), minimal address with country only is sufficient
            $countryCode = $buyer['address']['country'] ?? $buyer['country'] ?? 'SA';

            if (! empty($buyer['address'])) {
                $this->addPostalAddress($party, $buyer['address']);
            } elseif ($isSimplified) {
                // Simplified invoices can have minimal address
                $postal = $this->createElement('cac:PostalAddress');
                $country = $this->createElement('cac:Country');
                $this->addElement($country, 'cbc:IdentificationCode', $countryCode);
                $postal->appendChild($country);
                $party->appendChild($postal);
            } else {
                // Standard (B2B) invoice - build address from flat buyer data
                // For SA buyers (BR-KSA-63): street, building, postal, city, district, country required
                $postal = $this->createElement('cac:PostalAddress');

                // Street name (BT-50)
                if (! empty($buyer['street'])) {
                    $this->addElement($postal, 'cbc:StreetName', $buyer['street']);
                }

                // Building number (KSA-18) - Required for SA buyers
                if (! empty($buyer['building']) || ! empty($buyer['building_number'])) {
                    $this->addElement($postal, 'cbc:BuildingNumber', $buyer['building'] ?? $buyer['building_number']);
                }

                // Additional number (KSA-23)
                if (! empty($buyer['additional_number'])) {
                    $this->addElement($postal, 'cbc:PlotIdentification', $buyer['additional_number']);
                }

                // District (KSA-4)
                if (! empty($buyer['district'])) {
                    $this->addElement($postal, 'cbc:CitySubdivisionName', $buyer['district']);
                }

                // City (BT-52)
                if (! empty($buyer['city'])) {
                    $this->addElement($postal, 'cbc:CityName', $buyer['city']);
                }

                // Postal code (BT-53)
                if (! empty($buyer['postal_code']) || ! empty($buyer['postal_zone'])) {
                    $this->addElement($postal, 'cbc:PostalZone', $buyer['postal_code'] ?? $buyer['postal_zone']);
                }

                // Country (BT-55)
                $country = $this->createElement('cac:Country');
                $this->addElement($country, 'cbc:IdentificationCode', $countryCode);
                $postal->appendChild($country);
                $party->appendChild($postal);
            }

            // Party tax scheme (if has VAT number or for simplified)
            $taxScheme = $this->createElement('cac:PartyTaxScheme');
            if (! empty($buyer['vat_number'])) {
                $this->addElement($taxScheme, 'cbc:CompanyID', $buyer['vat_number']);
            }
            $taxSchemeInfo = $this->createElement('cac:TaxScheme');
            $this->addElement($taxSchemeInfo, 'cbc:ID', 'VAT');
            $taxScheme->appendChild($taxSchemeInfo);
            $party->appendChild($taxScheme);

            // Party legal entity
            $legalEntity = $this->createElement('cac:PartyLegalEntity');
            $this->addElement($legalEntity, 'cbc:RegistrationName', $buyer['name'] ?? 'Customer');
            $party->appendChild($legalEntity);
        }

        $customer->appendChild($party);
        $parent->appendChild($customer);
    }

    /**
     * Add postal address.
     *
     * ZATCA required fields for Saudi addresses:
     * - BT-35: Street name (cbc:StreetName)
     * - KSA-17: Building number (cbc:BuildingNumber) - 4 digits
     * - KSA-23: Additional number (cbc:PlotIdentification) - 4 digits
     * - BT-38: Postal code (cbc:PostalZone) - 5 digits for SA
     * - BT-37: City (cbc:CityName)
     * - KSA-3/KSA-4: District (cbc:CitySubdivisionName)
     * - BT-40/BT-55: Country code (cbc:IdentificationCode)
     */
    protected function addPostalAddress(DOMElement $party, array $address): void
    {
        $postal = $this->createElement('cac:PostalAddress');

        // Street name (BT-35)
        if (! empty($address['street'])) {
            $this->addElement($postal, 'cbc:StreetName', $address['street']);
        }

        // Additional street name / secondary address
        if (! empty($address['additional_street'])) {
            $this->addElement($postal, 'cbc:AdditionalStreetName', $address['additional_street']);
        }

        // Building number (KSA-17/KSA-18) - must be 4 digits per BR-KSA-37
        $buildingNumber = $address['building'] ?? $address['building_number'] ?? null;
        if (! empty($buildingNumber)) {
            $this->addElement($postal, 'cbc:BuildingNumber', $buildingNumber);
        }

        // Additional number (KSA-23) - must be 4 digits per BR-KSA-64
        // This is stored in PlotIdentification element
        $additionalNumber = $address['additional_number'] ?? null;
        if (! empty($additionalNumber)) {
            $this->addElement($postal, 'cbc:PlotIdentification', $additionalNumber);
        }

        // District (KSA-3/KSA-4) - MUST be in CitySubdivisionName
        if (! empty($address['district'])) {
            $this->addElement($postal, 'cbc:CitySubdivisionName', $address['district']);
        }

        // City (BT-37/BT-52)
        if (! empty($address['city'])) {
            $this->addElement($postal, 'cbc:CityName', $address['city']);
        }

        // Postal code (BT-38/BT-53) - 5 digits for SA
        if (! empty($address['postal_code'])) {
            $this->addElement($postal, 'cbc:PostalZone', $address['postal_code']);
        }

        // Province/State (CountrySubentity) - optional
        if (! empty($address['province'])) {
            $this->addElement($postal, 'cbc:CountrySubentity', $address['province']);
        }

        // Country (BT-40/BT-55)
        $country = $this->createElement('cac:Country');
        $this->addElement($country, 'cbc:IdentificationCode', $address['country'] ?? 'SA');
        $postal->appendChild($country);

        $party->appendChild($postal);
    }

    /**
     * Add delivery information.
     */
    protected function addDelivery(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $delivery = $this->createElement('cac:Delivery');

        // Use supply date if provided, otherwise use issue date
        $deliveryDate = $invoice->getSupplyDate() ?? $invoice->getIssueDate();
        $this->addElement($delivery, 'cbc:ActualDeliveryDate', $deliveryDate->format('Y-m-d'));

        $parent->appendChild($delivery);
    }

    /**
     * Add payment means.
     */
    protected function addPaymentMeans(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $paymentMeans = $this->createElement('cac:PaymentMeans');

        // Use payment method if provided, otherwise default to cash (10)
        $paymentCode = $invoice->getPaymentMethod() ?? '10';
        $this->addElement($paymentMeans, 'cbc:PaymentMeansCode', $paymentCode);

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
        $allowance = $this->createElement('cac:AllowanceCharge');
        $this->addElement($allowance, 'cbc:ChargeIndicator', 'false');
        $this->addElement($allowance, 'cbc:AllowanceChargeReason', 'Discount');

        $amount = $this->createElement('cbc:Amount', $this->formatAmount($invoice->getTotalDiscount()));
        $amount->setAttribute('currencyID', $invoice->getCurrency());
        $allowance->appendChild($amount);

        $parent->appendChild($allowance);
    }

    /**
     * Add tax total.
     */
    protected function addTaxTotal(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $taxTotal = $this->createElement('cac:TaxTotal');

        // Total tax amount
        $taxAmount = $this->createElement('cbc:TaxAmount', $this->formatAmount($invoice->getTotalVat()));
        $taxAmount->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal->appendChild($taxAmount);

        // Tax subtotals by category
        foreach ($invoice->getVatBreakdown() as $breakdown) {
            $this->addTaxSubtotal($taxTotal, $breakdown, $invoice->getCurrency());
        }

        $parent->appendChild($taxTotal);

        // Add second TaxTotal for tax currency (required by ZATCA)
        $taxTotal2 = $this->createElement('cac:TaxTotal');
        $taxAmount2 = $this->createElement('cbc:TaxAmount', $this->formatAmount($invoice->getTotalVat()));
        $taxAmount2->setAttribute('currencyID', $invoice->getCurrency());
        $taxTotal2->appendChild($taxAmount2);
        $parent->appendChild($taxTotal2);
    }

    /**
     * Add tax subtotal.
     */
    protected function addTaxSubtotal(DOMElement $taxTotal, array $breakdown, string $currency): void
    {
        $subtotal = $this->createElement('cac:TaxSubtotal');

        // Taxable amount
        $taxableAmount = $this->createElement('cbc:TaxableAmount', $this->formatAmount($breakdown['taxable_amount']));
        $taxableAmount->setAttribute('currencyID', $currency);
        $subtotal->appendChild($taxableAmount);

        // Tax amount
        $taxAmount = $this->createElement('cbc:TaxAmount', $this->formatAmount($breakdown['tax_amount']));
        $taxAmount->setAttribute('currencyID', $currency);
        $subtotal->appendChild($taxAmount);

        // Tax category
        $taxCategory = $this->createElement('cac:TaxCategory');
        $this->addElement($taxCategory, 'cbc:ID', $breakdown['category']->value);
        $this->addElement($taxCategory, 'cbc:Percent', $this->formatAmount($breakdown['rate']));

        // Add exemption reason if applicable
        if ($breakdown['exemption_reason'] && $breakdown['category'] !== VatCategory::STANDARD) {
            $this->addElement($taxCategory, 'cbc:TaxExemptionReasonCode', $breakdown['exemption_reason']->value);
            $this->addElement($taxCategory, 'cbc:TaxExemptionReason', $breakdown['exemption_reason']->description());
        }

        $taxScheme = $this->createElement('cac:TaxScheme');
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
        $monetary = $this->createElement('cac:LegalMonetaryTotal');

        // Line extension amount (sum of line subtotals)
        $lineExt = $this->createElement('cbc:LineExtensionAmount', $this->formatAmount($invoice->getSubtotal()));
        $lineExt->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($lineExt);

        // Tax exclusive amount
        $taxExcl = $this->createElement('cbc:TaxExclusiveAmount', $this->formatAmount($invoice->getSubtotal()));
        $taxExcl->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($taxExcl);

        // Tax inclusive amount
        $taxIncl = $this->createElement('cbc:TaxInclusiveAmount', $this->formatAmount($invoice->getTotalWithVat()));
        $taxIncl->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($taxIncl);

        // Allowance total
        if ($invoice->getTotalDiscount() > 0) {
            $allowance = $this->createElement('cbc:AllowanceTotalAmount', $this->formatAmount($invoice->getTotalDiscount()));
            $allowance->setAttribute('currencyID', $invoice->getCurrency());
            $monetary->appendChild($allowance);
        }

        // Payable amount
        $payable = $this->createElement('cbc:PayableAmount', $this->formatAmount($invoice->getTotalWithVat()));
        $payable->setAttribute('currencyID', $invoice->getCurrency());
        $monetary->appendChild($payable);

        $parent->appendChild($monetary);
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
        $line = $this->createElement('cac:InvoiceLine');

        // Line ID
        $this->addElement($line, 'cbc:ID', (string) $item->getId());

        // Invoiced quantity
        $quantity = $this->createElement('cbc:InvoicedQuantity', $this->formatQuantity($item->getQuantity()));
        $quantity->setAttribute('unitCode', $item->getUnitCode());
        $line->appendChild($quantity);

        // Line extension amount (net amount after discount: KSA-12 - BT-136)
        $lineExt = $this->createElement('cbc:LineExtensionAmount', $this->formatAmount($item->getSubtotal()));
        $lineExt->setAttribute('currencyID', $currency);
        $line->appendChild($lineExt);

        // Add line-level AllowanceCharge if there's a discount (BT-136)
        $discount = $item->getDiscount();
        if ($discount > 0) {
            $this->addLineAllowanceCharge($line, $item, $currency);
        }

        // Tax total for line
        $taxTotal = $this->createElement('cac:TaxTotal');
        $taxAmount = $this->createElement('cbc:TaxAmount', $this->formatAmount($item->getVatAmount()));
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);

        // Rounding amount (required)
        $roundingAmount = $this->createElement('cbc:RoundingAmount', $this->formatAmount($item->getTotal()));
        $roundingAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($roundingAmount);

        $line->appendChild($taxTotal);

        // Item
        $itemEl = $this->createElement('cac:Item');
        $this->addElement($itemEl, 'cbc:Name', $item->getName());

        // Classified tax category
        $taxCategory = $this->createElement('cac:ClassifiedTaxCategory');
        $this->addElement($taxCategory, 'cbc:ID', $item->getVatCategory()->value);
        $this->addElement($taxCategory, 'cbc:Percent', $this->formatAmount($item->getVatRate()));

        $taxScheme = $this->createElement('cac:TaxScheme');
        $this->addElement($taxScheme, 'cbc:ID', 'VAT');
        $taxCategory->appendChild($taxScheme);

        $itemEl->appendChild($taxCategory);
        $line->appendChild($itemEl);

        // Price (with base quantity for proper KSA-12 calculation)
        $price = $this->createElement('cac:Price');
        $priceAmount = $this->createElement('cbc:PriceAmount', $this->formatAmount($item->getUnitPrice()));
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);

        // Base quantity (defaults to 1)
        $baseQuantity = $this->createElement('cbc:BaseQuantity', '1');
        $baseQuantity->setAttribute('unitCode', $item->getUnitCode());
        $price->appendChild($baseQuantity);

        $line->appendChild($price);

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
        $allowance = $this->createElement('cac:AllowanceCharge');

        // ChargeIndicator: false = allowance/discount, true = charge
        $this->addElement($allowance, 'cbc:ChargeIndicator', 'false');

        // Allowance reason code (95 = Discount)
        $this->addElement($allowance, 'cbc:AllowanceChargeReasonCode', '95');

        // Allowance reason
        $this->addElement($allowance, 'cbc:AllowanceChargeReason', 'Discount');

        // Multiplier factor (discount percentage if applicable)
        $baseAmount = $item->getQuantity() * $item->getUnitPrice();
        if ($baseAmount > 0) {
            $discountPercent = ($item->getDiscount() / $baseAmount) * 100;
            $this->addElement($allowance, 'cbc:MultiplierFactorNumeric', $this->formatAmount($discountPercent));
        }

        // Discount amount (BT-136)
        $amount = $this->createElement('cbc:Amount', $this->formatAmount($item->getDiscount()));
        $amount->setAttribute('currencyID', $currency);
        $allowance->appendChild($amount);

        // Base amount (KSA-12: Quantity × Unit Price)
        $base = $this->createElement('cbc:BaseAmount', $this->formatAmount($baseAmount));
        $base->setAttribute('currencyID', $currency);
        $allowance->appendChild($base);

        $line->appendChild($allowance);
    }

    /**
     * Add a simple element.
     */
    protected function addElement(DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $this->createElement($name, $value);
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
     * Detect appropriate schemeID based on ID format for SELLER (BT-29).
     *
     * For seller identification, valid schemes are: CRN, MOM, MLS, SAG, OTH, 700
     * - CRN: Commercial Registration Number (10 digits)
     * - 700: 700 Number (10 digits starting with 7)
     * - MOM: MOMRAH license
     * - MLS: MHRSD license
     * - SAG: MISA/SAGIA license
     * - OTH: Other
     *
     * Note: NAT, IQA, TIN are for BUYER identification, not seller.
     */
    protected function detectSchemeId(string $id): string
    {
        // Remove any non-alphanumeric characters for analysis
        $cleanId = preg_replace('/[^a-zA-Z0-9]/', '', $id);

        // Check if it's exactly 10 numeric digits
        if (preg_match('/^\d{10}$/', $cleanId)) {
            $firstDigit = $cleanId[0];

            // Only 700 scheme has specific first-digit requirement
            if ($firstDigit === '7') {
                return '700';
            }

            // Default to CRN for 10-digit numbers (most common for sellers)
            return 'CRN';
        }

        // If not 10 digits, use OTH as fallback
        return 'OTH';
    }

    /**
     * Detect appropriate schemeID based on ID format for BUYER (BT-46).
     *
     * For buyer identification, valid schemes include:
     * - NAT: National ID (10 digits starting with 1)
     * - IQA: Iqama Number (10 digits starting with 2)
     * - TIN: Tax ID Number (10 digits starting with 3)
     * - CRN: Commercial Registration (10 digits)
     * - 700: 700 Number (10 digits starting with 7)
     * - OTH: Other
     */
    protected function detectBuyerSchemeId(string $id): string
    {
        // Remove any non-alphanumeric characters for analysis
        $cleanId = preg_replace('/[^a-zA-Z0-9]/', '', $id);

        // Check if it's exactly 10 numeric digits
        if (preg_match('/^\d{10}$/', $cleanId)) {
            $firstDigit = $cleanId[0];

            return match ($firstDigit) {
                '1' => 'NAT',  // National ID
                '2' => 'IQA',  // Iqama Number
                '3' => 'TIN',  // Tax ID Number
                '7' => '700',  // 700 Number
                default => 'CRN',  // Commercial Registration
            };
        }

        // If not 10 digits, use OTH as fallback
        return 'OTH';
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
