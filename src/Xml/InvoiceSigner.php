<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Contracts\InvoiceInterface;

/**
 * Turns an invoice into its signed UBL XML: generates the XML, hashes it, and signs it with a certificate.
 */
class InvoiceSigner
{
    public function __construct(
        protected readonly UblGenerator $generator,
        protected readonly XmlSigner $signer,
    ) {}

    /**
     * The invoice's hash and its XML signed with the certificate.
     */
    public function sign(InvoiceInterface $invoice, CertificateInterface $certificate): SignedInvoice
    {
        // Generate XML
        $xml = $this->generator->generate($invoice);

        // Generate hash and sign
        $hash = $this->signer->generateInvoiceHash($xml);

        $signedXml = $this->signer->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        return new SignedInvoice($hash, $signedXml);
    }
}
