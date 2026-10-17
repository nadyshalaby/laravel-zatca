<?php

namespace Corecave\Zatca\Enums;

/**
 * VAT exemption (or exception) reason codes specific to Saudi Arabia, as ZATCA publishes them: each
 * code, the VAT category it belongs to and its English text, from the table of the Electronic
 * Invoice XML Implementation Standard, version 1.2 of 19 May 2023, §11.2.4 (pages 32 and 33). A tax
 * subtotal carries the code as BT-121 and the text as BT-120; a standard-rated (S) one carries
 * neither.
 */
enum VatExemptionReason: string
{
    // Exempt from VAT (E)
    case FINANCIAL_SERVICES = 'VATEX-SA-29';
    case LIFE_INSURANCE = 'VATEX-SA-29-7';
    case REAL_ESTATE = 'VATEX-SA-30';

    // Zero rated (Z)
    case EXPORT_OF_GOODS = 'VATEX-SA-32';
    case EXPORT_OF_SERVICES = 'VATEX-SA-33';
    case INTERNATIONAL_TRANSPORT_OF_GOODS = 'VATEX-SA-34-1';
    case INTERNATIONAL_TRANSPORT_OF_PASSENGERS = 'VATEX-SA-34-2';
    case SERVICES_INCIDENTAL_TO_PASSENGER_TRANSPORT = 'VATEX-SA-34-3';
    case QUALIFYING_MEANS_OF_TRANSPORT = 'VATEX-SA-34-4';
    case SERVICES_RELATING_TO_TRANSPORT = 'VATEX-SA-34-5';
    case MEDICINES_AND_MEDICAL_EQUIPMENT = 'VATEX-SA-35';
    case QUALIFYING_METALS = 'VATEX-SA-36';
    case PRIVATE_EDUCATION = 'VATEX-SA-EDU';
    case PRIVATE_HEALTHCARE = 'VATEX-SA-HEA';
    case QUALIFIED_MILITARY_GOODS = 'VATEX-SA-MLTRY';

    // Not subject to VAT (O)
    case OUT_OF_SCOPE = 'VATEX-SA-OOS';

    /**
     * ZATCA's English text for the code, word for word as the standard prints it. Out of scope has
     * no text of ZATCA's, its reason being the taxpayer's own, given case by case; it reads as its
     * category's description until one is given.
     */
    public function description(): string
    {
        return match ($this) {
            self::FINANCIAL_SERVICES => 'Financial services mentioned in Article 29 of the VAT Regulations',
            self::LIFE_INSURANCE => 'Life insurance services mentioned in Article 29 of the VAT Regulations',
            self::REAL_ESTATE => 'Real estate transactions mentioned in Article 30 of the VAT Regulations',
            self::EXPORT_OF_GOODS => 'Export of goods',
            self::EXPORT_OF_SERVICES => 'Export of services',
            self::INTERNATIONAL_TRANSPORT_OF_GOODS => 'The international transport of Goods',
            self::INTERNATIONAL_TRANSPORT_OF_PASSENGERS => 'international transport of passengers',
            self::SERVICES_INCIDENTAL_TO_PASSENGER_TRANSPORT => 'services directly connected and incidental to a Supply of international passenger transport',
            self::QUALIFYING_MEANS_OF_TRANSPORT => 'Supply of a qualifying means of transport',
            self::SERVICES_RELATING_TO_TRANSPORT => 'Any services relating to Goods or passenger transportation, as defined in article twenty five of these Regulations',
            self::MEDICINES_AND_MEDICAL_EQUIPMENT => 'Medicines and medical equipment',
            self::QUALIFYING_METALS => 'Qualifying metals',
            self::PRIVATE_EDUCATION => 'Private education to citizen',
            self::PRIVATE_HEALTHCARE => 'Private healthcare to citizen',
            self::QUALIFIED_MILITARY_GOODS => 'supply of qualified military goods',
            self::OUT_OF_SCOPE => 'Not subject to VAT',
        };
    }

    /**
     * The VAT category ZATCA's table files the code under.
     */
    public function category(): VatCategory
    {
        return match ($this) {
            self::FINANCIAL_SERVICES,
            self::LIFE_INSURANCE,
            self::REAL_ESTATE => VatCategory::EXEMPT,

            self::EXPORT_OF_GOODS,
            self::EXPORT_OF_SERVICES,
            self::INTERNATIONAL_TRANSPORT_OF_GOODS,
            self::INTERNATIONAL_TRANSPORT_OF_PASSENGERS,
            self::SERVICES_INCIDENTAL_TO_PASSENGER_TRANSPORT,
            self::QUALIFYING_MEANS_OF_TRANSPORT,
            self::SERVICES_RELATING_TO_TRANSPORT,
            self::MEDICINES_AND_MEDICAL_EQUIPMENT,
            self::QUALIFYING_METALS,
            self::PRIVATE_EDUCATION,
            self::PRIVATE_HEALTHCARE,
            self::QUALIFIED_MILITARY_GOODS => VatCategory::ZERO_RATED,

            self::OUT_OF_SCOPE => VatCategory::OUT_OF_SCOPE,
        };
    }
}
