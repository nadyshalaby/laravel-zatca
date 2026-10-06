<?php

namespace Corecave\Zatca\Facades;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\ZatcaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InvoiceBuilder invoice()
 * @method static \Corecave\Zatca\Certificate\CertificateManager certificate()
 * @method static \Corecave\Zatca\Certificate\CsrGenerator csr()
 * @method static \Corecave\Zatca\Contracts\ApiClientInterface client()
 * @method static \Corecave\Zatca\Xml\UblGenerator xml()
 * @method static \Corecave\Zatca\Xml\XmlSigner signer()
 * @method static \Corecave\Zatca\Xml\XmlValidator validator()
 * @method static \Corecave\Zatca\Qr\QrGenerator qr()
 * @method static \Corecave\Zatca\Hash\HashChainManager hashChain()
 * @method static \Corecave\Zatca\Debug\DebugDumper debug()
 * @method static \Corecave\Zatca\Results\ReportResult report(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ClearanceResult clear(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ProcessResult process(InvoiceInterface $invoice)
 * @method static string generateXml(InvoiceInterface $invoice)
 * @method static string signXml(string $xml)
 * @method static bool validateXml(string $xml)
 * @method static string generateQrCode(InvoiceInterface $invoice, \Corecave\Zatca\Qr\QrSignature $signature)
 * @method static bool isSandbox()
 * @method static \Corecave\Zatca\Contracts\CertificateInterface getOrCreateCertificate(bool $forceNew = false)
 * @method static \Corecave\Zatca\Contracts\CertificateInterface autoOnboard(string $otp = '123456')
 * @method static array signWithFreshCertificate(InvoiceInterface $invoice)
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
