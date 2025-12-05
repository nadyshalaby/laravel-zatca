<?php

namespace Corecave\Zatca\Facades;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\ZatcaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InvoiceBuilder invoice()
 * @method static \Corecave\Zatca\Certificate\CertificateManager certificate()
 * @method static \Corecave\Zatca\Client\ZatcaClient client()
 * @method static \Corecave\Zatca\Results\ReportResult report(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ClearanceResult clear(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ProcessResult process(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ComplianceResult compliance()
 * @method static string generateQrCode(InvoiceInterface $invoice, string $signature, string $publicKey)
 * @method static string generateXml(InvoiceInterface $invoice)
 * @method static string signXml(string $xml)
 * @method static bool validateXml(string $xml)
 *
 * @see ZatcaManager
 */
class Zatca extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'zatca';
    }
}
