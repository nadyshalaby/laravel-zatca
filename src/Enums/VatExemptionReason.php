<?php

namespace Corecave\Zatca\Enums;

/**
 * VAT Exemption Reason codes as per ZATCA specification.
 */
enum VatExemptionReason: string
{
    // Zero-rated reasons
    case EXPORT = 'VATEX-SA-32';
    case EXPORT_OF_SERVICES = 'VATEX-SA-33';
    case INTERNATIONAL_TRANSPORT = 'VATEX-SA-34-1';
    case INTERNATIONAL_TRANSPORT_SERVICES = 'VATEX-SA-34-2';
    case INTERNATIONAL_TRANSPORT_SUPPLIES = 'VATEX-SA-34-3';
    case INTERNATIONAL_TRANSPORT_GOODS = 'VATEX-SA-34-4';
    case INTERNATIONAL_TRANSPORT_PASSENGER = 'VATEX-SA-34-5';
    case QUALIFYING_MEDICINES = 'VATEX-SA-35';
    case QUALIFYING_MEDICAL_EQUIPMENT = 'VATEX-SA-36';
    case QUALIFYING_METALS = 'VATEX-SA-EDU';
    case PRIVATE_EDUCATION = 'VATEX-SA-HEA';
    case PRIVATE_HEALTH = 'VATEX-SA-MLTRY';

    // Exempt reasons
    case FINANCIAL_SERVICES = 'VATEX-SA-29';
    case FINANCIAL_SERVICES_EXPLICIT = 'VATEX-SA-29-7';
    case LIFE_INSURANCE = 'VATEX-SA-30';
    case REAL_ESTATE = 'VATEX-SA-EDU';

    // Out of scope reasons
    case OUT_OF_SCOPE = 'VATEX-SA-OOS';

    /**
     * Get the description for this exemption reason.
     */
    public function description(): string
    {
        return match ($this) {
            self::EXPORT => 'Export of goods',
            self::EXPORT_OF_SERVICES => 'Export of services',
            self::INTERNATIONAL_TRANSPORT => 'International transport',
            self::INTERNATIONAL_TRANSPORT_SERVICES => 'Services related to international transport',
            self::INTERNATIONAL_TRANSPORT_SUPPLIES => 'Supplies related to international transport',
            self::INTERNATIONAL_TRANSPORT_GOODS => 'International transport of goods',
            self::INTERNATIONAL_TRANSPORT_PASSENGER => 'International passenger transport',
            self::QUALIFYING_MEDICINES => 'Supply of qualifying medicines',
            self::QUALIFYING_MEDICAL_EQUIPMENT => 'Supply of qualifying medical equipment',
            self::QUALIFYING_METALS => 'Supply of qualifying metals',
            self::PRIVATE_EDUCATION => 'Private education services',
            self::PRIVATE_HEALTH => 'Private healthcare services',
            self::FINANCIAL_SERVICES => 'Financial services',
            self::FINANCIAL_SERVICES_EXPLICIT => 'Financial services mentioned in Article 29',
            self::LIFE_INSURANCE => 'Life insurance',
            self::REAL_ESTATE => 'Real estate transactions',
            self::OUT_OF_SCOPE => 'Not subject to VAT',
        };
    }

    /**
     * Get the VAT category this exemption reason belongs to.
     */
    public function category(): VatCategory
    {
        return match ($this) {
            self::EXPORT,
            self::EXPORT_OF_SERVICES,
            self::INTERNATIONAL_TRANSPORT,
            self::INTERNATIONAL_TRANSPORT_SERVICES,
            self::INTERNATIONAL_TRANSPORT_SUPPLIES,
            self::INTERNATIONAL_TRANSPORT_GOODS,
            self::INTERNATIONAL_TRANSPORT_PASSENGER,
            self::QUALIFYING_MEDICINES,
            self::QUALIFYING_MEDICAL_EQUIPMENT,
            self::QUALIFYING_METALS,
            self::PRIVATE_EDUCATION,
            self::PRIVATE_HEALTH => VatCategory::ZERO_RATED,

            self::FINANCIAL_SERVICES,
            self::FINANCIAL_SERVICES_EXPLICIT,
            self::LIFE_INSURANCE,
            self::REAL_ESTATE => VatCategory::EXEMPT,

            self::OUT_OF_SCOPE => VatCategory::OUT_OF_SCOPE,
        };
    }
}
