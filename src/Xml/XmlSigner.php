<?php

namespace Corecave\Zatca\Xml;

use DOMDocument;
use DOMXPath;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\Hash;

class XmlSigner
{
    /**
     * XML Namespaces.
     */
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    private const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    /**
     * Sign an XML invoice using ECDSA with SHA-256.
     */
    public function sign(string $xml, string $privateKeyPem, string $certificatePem): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        // Canonicalize the invoice content (without signature)
        $invoiceHash = $this->generateInvoiceHash($xml);

        // Load private key
        $privateKey = EC::loadPrivateKey($privateKeyPem);

        // Create signature
        $signatureValue = $this->createSignature($invoiceHash, $privateKey);

        // Get certificate info
        $certInfo = $this->extractCertificateInfo($certificatePem);

        // Build and insert the signature into the XML
        $signedXml = $this->insertSignature($doc, $invoiceHash, $signatureValue, $certificatePem, $certInfo);

        return $signedXml;
    }

    /**
     * Generate invoice hash (SHA-256 of canonicalized invoice).
     */
    public function generateInvoiceHash(string $xml): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        // Remove existing signature elements for hashing
        $this->removeSignatureElements($doc);

        // Remove QR code value for hashing
        $this->removeQrCodeValue($doc);

        // Canonicalize (C14N)
        $canonical = $doc->documentElement->C14N(false, false);

        // SHA-256 hash
        return base64_encode(hash('sha256', $canonical, true));
    }

    /**
     * Create ECDSA signature.
     */
    protected function createSignature(string $digestBase64, $privateKey): string
    {
        $digest = base64_decode($digestBase64);

        // Sign with ECDSA-SHA256
        $privateKey = $privateKey->withHash('sha256');
        $signature = $privateKey->sign($digest);

        return base64_encode($signature);
    }

    /**
     * Insert signature into the XML document.
     */
    protected function insertSignature(
        DOMDocument $doc,
        string $invoiceHash,
        string $signatureValue,
        string $certificate,
        array $certInfo
    ): string {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $xpath->registerNamespace('sig', self::NS_SIG);
        $xpath->registerNamespace('sac', self::NS_SAC);

        // Find signature placeholder
        $sigPlaceholder = $xpath->query('//ds:Signature[@Id="signature"]')->item(0);

        if (! $sigPlaceholder) {
            // Find alternative location
            $sigPlaceholder = $xpath->query('//sac:SignatureInformation/ds:Signature')->item(0);
        }

        if (! $sigPlaceholder) {
            throw new \RuntimeException('Signature placeholder not found in XML');
        }

        // Clear placeholder content
        while ($sigPlaceholder->firstChild) {
            $sigPlaceholder->removeChild($sigPlaceholder->firstChild);
        }

        // Add SignedInfo
        $signedInfo = $this->createSignedInfo($doc, $invoiceHash);
        $sigPlaceholder->appendChild($signedInfo);

        // Add SignatureValue
        $sigValueEl = $doc->createElementNS(self::NS_DS, 'ds:SignatureValue', $signatureValue);
        $sigPlaceholder->appendChild($sigValueEl);

        // Add KeyInfo
        $keyInfo = $this->createKeyInfo($doc, $certificate, $certInfo);
        $sigPlaceholder->appendChild($keyInfo);

        // Add Object (XAdES)
        $object = $this->createXadesObject($doc, $invoiceHash, $certInfo);
        $sigPlaceholder->appendChild($object);

        return $doc->saveXML();
    }

    /**
     * Create SignedInfo element.
     */
    protected function createSignedInfo(DOMDocument $doc, string $invoiceHash): \DOMElement
    {
        $signedInfo = $doc->createElementNS(self::NS_DS, 'ds:SignedInfo');

        // CanonicalizationMethod
        $canonMethod = $doc->createElementNS(self::NS_DS, 'ds:CanonicalizationMethod');
        $canonMethod->setAttribute('Algorithm', 'http://www.w3.org/2006/12/xml-c14n11');
        $signedInfo->appendChild($canonMethod);

        // SignatureMethod
        $sigMethod = $doc->createElementNS(self::NS_DS, 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256');
        $signedInfo->appendChild($sigMethod);

        // Reference (invoice)
        $reference = $doc->createElementNS(self::NS_DS, 'ds:Reference');
        $reference->setAttribute('Id', 'invoiceSignedData');
        $reference->setAttribute('URI', '');

        $transforms = $doc->createElementNS(self::NS_DS, 'ds:Transforms');

        // XPath transform
        $xpathTransform = $doc->createElementNS(self::NS_DS, 'ds:Transform');
        $xpathTransform->setAttribute('Algorithm', 'http://www.w3.org/TR/1999/REC-xpath-19991116');
        $xpathEl = $doc->createElementNS(self::NS_DS, 'ds:XPath', 'not(//ancestor-or-self::ext:UBLExtensions)');
        $xpathTransform->appendChild($xpathEl);
        $transforms->appendChild($xpathTransform);

        // C14N transform
        $c14nTransform = $doc->createElementNS(self::NS_DS, 'ds:Transform');
        $c14nTransform->setAttribute('Algorithm', 'http://www.w3.org/2006/12/xml-c14n11');
        $transforms->appendChild($c14nTransform);

        $reference->appendChild($transforms);

        $digestMethod = $doc->createElementNS(self::NS_DS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);

        $digestValue = $doc->createElementNS(self::NS_DS, 'ds:DigestValue', $invoiceHash);
        $reference->appendChild($digestValue);

        $signedInfo->appendChild($reference);

        // Reference (XAdES SignedProperties)
        $xadesRef = $doc->createElementNS(self::NS_DS, 'ds:Reference');
        $xadesRef->setAttribute('Type', 'http://www.w3.org/2000/09/xmldsig#SignatureProperties');
        $xadesRef->setAttribute('URI', '#xadesSignedProperties');

        $xadesDigestMethod = $doc->createElementNS(self::NS_DS, 'ds:DigestMethod');
        $xadesDigestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $xadesRef->appendChild($xadesDigestMethod);

        // Will be calculated and filled
        $xadesDigestValue = $doc->createElementNS(self::NS_DS, 'ds:DigestValue', '');
        $xadesRef->appendChild($xadesDigestValue);

        $signedInfo->appendChild($xadesRef);

        return $signedInfo;
    }

    /**
     * Create KeyInfo element.
     */
    protected function createKeyInfo(DOMDocument $doc, string $certificate, array $certInfo): \DOMElement
    {
        $keyInfo = $doc->createElementNS(self::NS_DS, 'ds:KeyInfo');

        // X509Data
        $x509Data = $doc->createElementNS(self::NS_DS, 'ds:X509Data');

        // X509Certificate
        $certContent = str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $certificate
        );
        $x509Cert = $doc->createElementNS(self::NS_DS, 'ds:X509Certificate', $certContent);
        $x509Data->appendChild($x509Cert);

        $keyInfo->appendChild($x509Data);

        return $keyInfo;
    }

    /**
     * Create XAdES Object element.
     */
    protected function createXadesObject(DOMDocument $doc, string $invoiceHash, array $certInfo): \DOMElement
    {
        $object = $doc->createElementNS(self::NS_DS, 'ds:Object');

        $qualifyingProperties = $doc->createElementNS(self::NS_XADES, 'xades:QualifyingProperties');
        $qualifyingProperties->setAttribute('Target', 'signature');

        $signedProperties = $doc->createElementNS(self::NS_XADES, 'xades:SignedProperties');
        $signedProperties->setAttribute('Id', 'xadesSignedProperties');

        // SignedSignatureProperties
        $signedSigProps = $doc->createElementNS(self::NS_XADES, 'xades:SignedSignatureProperties');

        // SigningTime
        $signingTime = $doc->createElementNS(self::NS_XADES, 'xades:SigningTime', gmdate('Y-m-d\TH:i:s\Z'));
        $signedSigProps->appendChild($signingTime);

        // SigningCertificate
        $signingCert = $doc->createElementNS(self::NS_XADES, 'xades:SigningCertificate');
        $cert = $doc->createElementNS(self::NS_XADES, 'xades:Cert');

        $certDigest = $doc->createElementNS(self::NS_XADES, 'xades:CertDigest');
        $digestMethod = $doc->createElementNS(self::NS_DS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $certDigest->appendChild($digestMethod);

        $digestValue = $doc->createElementNS(self::NS_DS, 'ds:DigestValue', $certInfo['digest'] ?? '');
        $certDigest->appendChild($digestValue);
        $cert->appendChild($certDigest);

        $issuerSerial = $doc->createElementNS(self::NS_XADES, 'xades:IssuerSerial');
        $issuerName = $doc->createElementNS(self::NS_DS, 'ds:X509IssuerName', $certInfo['issuer'] ?? '');
        $issuerSerial->appendChild($issuerName);
        $serialNumber = $doc->createElementNS(self::NS_DS, 'ds:X509SerialNumber', $certInfo['serial'] ?? '');
        $issuerSerial->appendChild($serialNumber);
        $cert->appendChild($issuerSerial);

        $signingCert->appendChild($cert);
        $signedSigProps->appendChild($signingCert);

        $signedProperties->appendChild($signedSigProps);
        $qualifyingProperties->appendChild($signedProperties);
        $object->appendChild($qualifyingProperties);

        return $object;
    }

    /**
     * Extract certificate information.
     */
    protected function extractCertificateInfo(string $certificatePem): array
    {
        // Clean up certificate
        if (! str_contains($certificatePem, '-----BEGIN CERTIFICATE-----')) {
            $certificatePem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certificatePem, 64, "\n")."-----END CERTIFICATE-----";
        }

        $certData = openssl_x509_parse($certificatePem);
        $certContent = str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $certificatePem
        );

        // Certificate digest
        $digest = base64_encode(hash('sha256', base64_decode($certContent), true));

        // Build issuer string
        $issuerParts = [];
        if (isset($certData['issuer'])) {
            foreach ($certData['issuer'] as $key => $value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $issuerParts[] = "{$key}={$value}";
            }
        }

        return [
            'digest' => $digest,
            'issuer' => implode(', ', array_reverse($issuerParts)),
            'serial' => $certData['serialNumber'] ?? '',
            'subject' => $certData['subject'] ?? [],
        ];
    }

    /**
     * Remove signature elements for hashing.
     */
    protected function removeSignatureElements(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ext', self::NS_EXT);

        // Remove UBLExtensions content
        $extensions = $xpath->query('//ext:UBLExtensions');
        foreach ($extensions as $ext) {
            // Clear children but keep element
            while ($ext->firstChild) {
                $ext->removeChild($ext->firstChild);
            }
        }
    }

    /**
     * Remove QR code value for hashing.
     */
    protected function removeQrCodeValue(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $qrNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        foreach ($qrNodes as $node) {
            $node->nodeValue = '';
        }
    }

    /**
     * Get signature value from signed XML.
     */
    public function getSignatureValue(string $signedXml): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($signedXml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);

        $sigValues = $xpath->query('//ds:SignatureValue');

        if ($sigValues && $sigValues->length > 0) {
            return $sigValues->item(0)->nodeValue;
        }

        return '';
    }

    /**
     * Verify XML signature.
     */
    public function verify(string $signedXml, string $publicKeyPem): bool
    {
        $doc = new DOMDocument;
        $doc->loadXML($signedXml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);

        // Get signature value
        $signatureValue = $this->getSignatureValue($signedXml);

        if (empty($signatureValue)) {
            return false;
        }

        // Get digest value
        $digestNodes = $xpath->query('//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue');

        if (! $digestNodes || $digestNodes->length === 0) {
            return false;
        }

        $storedDigest = $digestNodes->item(0)->nodeValue;

        // Calculate digest of invoice
        $calculatedDigest = $this->generateInvoiceHash($signedXml);

        // Compare digests
        if ($storedDigest !== $calculatedDigest) {
            return false;
        }

        // Verify signature
        try {
            $publicKey = EC::loadPublicKey($publicKeyPem);
            $publicKey = $publicKey->withHash('sha256');

            return $publicKey->verify(
                base64_decode($storedDigest),
                base64_decode($signatureValue)
            );
        } catch (\Exception $e) {
            return false;
        }
    }
}
