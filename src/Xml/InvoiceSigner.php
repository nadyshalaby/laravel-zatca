<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Qr\QrSignature;

/**
 * Turns an invoice into its signed UBL XML: generates the XML, hashes it, signs it with a certificate, and
 * writes its QR code in, since ZATCA refuses a signed document with an empty QR code (BR-CL-KSA-14 and
 * QRCODE_INVALID in the sandbox, 2026-10-07), a standard invoice as much as a simplified one.
 */
class InvoiceSigner
{
    public function __construct(
        protected readonly UblGenerator $generator,
        protected readonly XmlSigner $signer,
        protected readonly QrGenerator $qrCodes,
    ) {}

    /**
     * The invoice's hash, its XML signed with the certificate and carrying its QR code, and the QR code.
     * The invoice keeps the hash, which the QR code's tag 6 reads; the QR code is outside the hash.
     */
    public function sign(InvoiceInterface $invoice, CertificateInterface $certificate): SignedInvoice
    {
        $xml = $this->generator->generate($invoice);
        $hash = $this->signer->generateInvoiceHash($xml);
        $signedXml = $this->signer->sign($xml, $certificate->getPrivateKey(), $certificate->getCertificatePem());
        $invoice->setHash($hash);
        $qrCode = $this->qrCodes->generate($invoice, new QrSignature(
            $this->signer->getSignatureValue($signedXml),
            $certificate->getPublicKeyRaw(),
            $certificate->getCertificateSignature(),
        ));

        return new SignedInvoice($hash, $this->generator->addQrCode($signedXml, $qrCode), $qrCode);
    }
}
