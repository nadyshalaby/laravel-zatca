<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\InvoiceInterface;
use DOMElement;

/**
 * Writes the seller and buyer parties of a UBL invoice.
 */
class UblPartyWriter
{
    public function __construct(
        protected readonly UblElements $elements,
    ) {}

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
     *
     * @param  array<string, mixed>  $seller
     */
    public function addSupplierParty(DOMElement $parent, array $seller): void
    {
        $supplier = $this->elements->createElement('cac:AccountingSupplierParty');
        $party = $this->elements->createElement('cac:Party');

        // Party identification (BT-29) - REQUIRED by ZATCA BR-KSA-08
        $this->addSellerIdentification($party, $seller);

        // Postal address (BR-KSA-09 requires full address)
        $this->addPostalAddress($party, $seller['address'] ?? []);

        // Party tax scheme
        $taxScheme = $this->elements->createElement('cac:PartyTaxScheme');
        $this->elements->addElement($taxScheme, 'cbc:CompanyID', $seller['vat_number']);
        $this->elements->addVatTaxScheme($taxScheme);
        $party->appendChild($taxScheme);

        // Party legal entity
        $this->addLegalEntity($party, $seller['name_ar'] ?? $seller['name']);

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
    public function addCustomerParty(DOMElement $parent, InvoiceInterface $invoice): void
    {
        $buyer = $invoice->getBuyer();
        $customer = $this->elements->createElement('cac:AccountingCustomerParty');
        $party = $this->elements->createElement('cac:Party');

        // For simplified invoices without buyer info, use minimal placeholder
        if ($invoice->isSimplified() && empty($buyer)) {
            $this->addAnonymousBuyer($party);
        } else {
            // Use provided buyer info
            $this->addBuyer($party, $buyer ?? [], $invoice);
        }

        $customer->appendChild($party);
        $parent->appendChild($customer);
    }

    /**
     * Add the seller's party identification (BT-29).
     *
     * Use registration_number if available, otherwise use CRN from VAT number (middle 10 digits)
     *
     * @param  array<string, mixed>  $seller
     */
    protected function addSellerIdentification(DOMElement $party, array $seller): void
    {
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

        $this->addPartyIdentification($party, $regNumber, $schemeId);
    }

    /**
     * Add the placeholder buyer of a simplified invoice that names none.
     */
    protected function addAnonymousBuyer(DOMElement $party): void
    {
        // Party identification with NAT scheme for individual customers
        $this->addPartyIdentification($party, '1000000000', 'NAT');

        // Minimal postal address with country only (allowed for B2C)
        $this->addCountryOnlyAddress($party, 'SA');

        // Party tax scheme (required even for simplified)
        $this->addBuyerTaxScheme($party, []);

        // Party legal entity with placeholder name
        $this->addLegalEntity($party, 'Individual Customer');
    }

    /**
     * Add the buyer the invoice names: its identification, postal address, tax scheme and legal entity.
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function addBuyer(DOMElement $party, array $buyer, InvoiceInterface $invoice): void
    {
        // Party identification (BT-46) - REQUIRED for all invoices
        $this->addBuyerIdentification($party, $buyer);

        // Postal address
        $this->addBuyerAddress($party, $buyer, $invoice);

        // Party tax scheme (if has VAT number or for simplified)
        $this->addBuyerTaxScheme($party, $buyer);

        // Party legal entity
        $this->addLegalEntity($party, $buyer['name'] ?? 'Customer');
    }

    /**
     * Add the buyer's party identification (BT-46).
     *
     * Use registration_number/national_id with appropriate schemeID
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function addBuyerIdentification(DOMElement $party, array $buyer): void
    {
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
        } else {
            // Without any ID, use placeholder NAT, simplified and standard alike.
            // For standard invoices this will trigger BR-KSA-F-13 warning but at least the XML structure is valid
            $buyerId = '1000000000';
            $schemeId = 'NAT';
        }

        $this->addPartyIdentification($party, $buyerId, $schemeId);
    }

    /**
     * Add the buyer's postal address.
     *
     * For B2B (standard) with SA buyer, full address is required
     * For B2C (simplified), minimal address with country only is sufficient
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function addBuyerAddress(DOMElement $party, array $buyer, InvoiceInterface $invoice): void
    {
        $countryCode = $buyer['address']['country'] ?? $buyer['country'] ?? 'SA';

        if (! empty($buyer['address'])) {
            $this->addPostalAddress($party, $buyer['address']);
        } elseif ($invoice->isSimplified()) {
            // Simplified invoices can have minimal address
            $this->addCountryOnlyAddress($party, $countryCode);
        } else {
            // Standard (B2B) invoice - build address from flat buyer data
            $this->addFlatBuyerAddress($party, $buyer, $countryCode);
        }
    }

    /**
     * Add the address of a standard invoice's buyer from its flat data.
     *
     * For SA buyers (BR-KSA-63): street, building, postal, city, district, country required
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function addFlatBuyerAddress(DOMElement $party, array $buyer, string $countryCode): void
    {
        $postal = $this->elements->createElement('cac:PostalAddress');

        // Street name (BT-50)
        if (! empty($buyer['street'])) {
            $this->elements->addElement($postal, 'cbc:StreetName', $buyer['street']);
        }

        // Building number (KSA-18) - Required for SA buyers
        if (! empty($buyer['building']) || ! empty($buyer['building_number'])) {
            $this->elements->addElement($postal, 'cbc:BuildingNumber', $buyer['building'] ?? $buyer['building_number']);
        }

        // Additional number (KSA-23)
        if (! empty($buyer['additional_number'])) {
            $this->elements->addElement($postal, 'cbc:PlotIdentification', $buyer['additional_number']);
        }

        // District (KSA-4)
        if (! empty($buyer['district'])) {
            $this->elements->addElement($postal, 'cbc:CitySubdivisionName', $buyer['district']);
        }

        // City (BT-52)
        if (! empty($buyer['city'])) {
            $this->elements->addElement($postal, 'cbc:CityName', $buyer['city']);
        }

        // Postal code (BT-53)
        if (! empty($buyer['postal_code']) || ! empty($buyer['postal_zone'])) {
            $this->elements->addElement($postal, 'cbc:PostalZone', $buyer['postal_code'] ?? $buyer['postal_zone']);
        }

        // Country (BT-55)
        $this->addCountry($postal, $countryCode);
        $party->appendChild($postal);
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
     *
     * @param  array<string, mixed>  $address
     */
    protected function addPostalAddress(DOMElement $party, array $address): void
    {
        $postal = $this->elements->createElement('cac:PostalAddress');

        $optionalElements = [
            // Street name (BT-35)
            'cbc:StreetName' => $address['street'] ?? null,
            // Additional street name / secondary address
            'cbc:AdditionalStreetName' => $address['additional_street'] ?? null,
            // Building number (KSA-17/KSA-18) - must be 4 digits per BR-KSA-37
            'cbc:BuildingNumber' => $address['building'] ?? $address['building_number'] ?? null,
            // Additional number (KSA-23) - must be 4 digits per BR-KSA-64, stored in PlotIdentification
            'cbc:PlotIdentification' => $address['additional_number'] ?? null,
            // District (KSA-3/KSA-4) - MUST be in CitySubdivisionName
            'cbc:CitySubdivisionName' => $address['district'] ?? null,
            // City (BT-37/BT-52)
            'cbc:CityName' => $address['city'] ?? null,
            // Postal code (BT-38/BT-53) - 5 digits for SA
            'cbc:PostalZone' => $address['postal_code'] ?? null,
            // Province/State (CountrySubentity) - optional
            'cbc:CountrySubentity' => $address['province'] ?? null,
        ];

        foreach ($optionalElements as $name => $value) {
            if (! empty($value)) {
                $this->elements->addElement($postal, $name, $value);
            }
        }

        // Country (BT-40/BT-55)
        $this->addCountry($postal, $address['country'] ?? 'SA');

        $party->appendChild($postal);
    }

    /**
     * Add a postal address that names the country only.
     */
    protected function addCountryOnlyAddress(DOMElement $party, string $countryCode): void
    {
        $postal = $this->elements->createElement('cac:PostalAddress');
        $this->addCountry($postal, $countryCode);
        $party->appendChild($postal);
    }

    /**
     * Add the country to a postal address.
     */
    protected function addCountry(DOMElement $postal, string $countryCode): void
    {
        $country = $this->elements->createElement('cac:Country');
        $this->elements->addElement($country, 'cbc:IdentificationCode', $countryCode);
        $postal->appendChild($country);
    }

    /**
     * Add a party identification: the ID with its schemeID.
     */
    protected function addPartyIdentification(DOMElement $party, string $id, string $schemeId): void
    {
        $partyId = $this->elements->createElement('cac:PartyIdentification');
        $idElement = $this->elements->createElement('cbc:ID', $id);
        $idElement->setAttribute('schemeID', $schemeId);
        $partyId->appendChild($idElement);
        $party->appendChild($partyId);
    }

    /**
     * Add the buyer's party tax scheme, with its VAT number when it has one.
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function addBuyerTaxScheme(DOMElement $party, array $buyer): void
    {
        $taxScheme = $this->elements->createElement('cac:PartyTaxScheme');
        if (! empty($buyer['vat_number'])) {
            $this->elements->addElement($taxScheme, 'cbc:CompanyID', $buyer['vat_number']);
        }
        $this->elements->addVatTaxScheme($taxScheme);
        $party->appendChild($taxScheme);
    }

    /**
     * Add the party legal entity with its registration name.
     */
    protected function addLegalEntity(DOMElement $party, string $registrationName): void
    {
        $legalEntity = $this->elements->createElement('cac:PartyLegalEntity');
        $this->elements->addElement($legalEntity, 'cbc:RegistrationName', $registrationName);
        $party->appendChild($legalEntity);
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
}
