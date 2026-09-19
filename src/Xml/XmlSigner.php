<?php

namespace Corecave\Zatca\Xml;

use Corecave\Zatca\Exceptions\ZatcaException;
use DOMDocument;
use DOMXPath;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\EC\PublicKey;
use phpseclib3\File\X509;

class XmlSigner
{
    /**
     * XML Namespaces.
     */
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Sign an XML invoice using ECDSA with SHA-256.
     *
     * This implementation follows the ZATCA SDK approach:
     * 1. Insert the UBL extensions template with placeholder values
     * 2. Populate the values (cert digest, issuer, serial, signing time)
     * 3. Extract the SignedProperties node from actual XML and hash it
     * 4. Compute the signature
     * 5. Populate remaining values (signature, hashes)
     */
    public function sign(string $xml, string $privateKeyPem, string $certificatePem): string
    {
        // Compute invoice hash before adding signature elements
        $invoiceHash = $this->generateInvoiceHash($xml);

        // Load private key
        $privateKey = EC::loadPrivateKey($privateKeyPem);

        // Get certificate info
        $certInfo = $this->extractCertificateInfo($certificatePem);

        // Get certificate content without PEM headers
        $certContent = $this->certificateBody($certificatePem);

        // Generate signing time
        $signingTime = gmdate('Y-m-d\TH:i:s');

        // Sign the binary invoice hash directly
        $signatureValue = $this->createSignature(base64_decode($invoiceHash), $privateKey);

        // Compute SignedProperties hash following SDK approach
        $signedPropsHash = $this->computeSignedPropertiesHashSdkStyle($signingTime, $certInfo);

        // Build and insert the complete signature
        $signatureXml = implode("\n", [
            $this->signedInfoXml($invoiceHash, $signedPropsHash),
            $this->keyInfoXml($signatureValue, $certContent),
            $this->signatureObjectXml($signingTime, $certInfo),
        ]);

        return $this->insertSignature($xml, $signatureXml);
    }

    /**
     * Generate invoice hash (SHA-256 of cleaned invoice XML).
     *
     * ZATCA requires the hash to be calculated by:
     * 1. Removing UBLExtensions, Signature, and QR code elements
     * 2. Removing XML declaration
     * 3. Computing SHA-256 hash
     * 4. Base64 encoding the result
     */
    public function generateInvoiceHash(string $xml): string
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);

        // Apply transforms to exclude UBLExtensions, Signature, and QR elements
        $cleanedXml = $this->applyHashTransforms($doc);

        // SHA-256 hash of the cleaned XML (binary output, then base64)
        $hash = hash('sha256', $cleanedXml, true);

        return base64_encode($hash);
    }

    /**
     * Apply ZATCA-required transforms for hash calculation.
     *
     * The transforms exclude:
     * - ext:UBLExtensions (contains signature)
     * - cac:Signature (signature placeholder)
     * - cac:AdditionalDocumentReference where ID='QR' (QR code)
     *
     * Uses C14N canonicalization as per ZATCA spec.
     */
    protected function applyHashTransforms(DOMDocument $doc): string
    {
        // Remove UBLExtensions element
        $extensions = $doc->getElementsByTagName('UBLExtensions');
        while ($extensions->length > 0) {
            $extensions->item(0)->parentNode->removeChild($extensions->item(0));
        }

        // Remove cac:Signature element
        $signatures = $doc->getElementsByTagName('Signature');
        while ($signatures->length > 0) {
            $signatures->item(0)->parentNode->removeChild($signatures->item(0));
        }

        // Remove QR code AdditionalDocumentReference
        $additionalRefs = $doc->getElementsByTagName('AdditionalDocumentReference');
        foreach ($additionalRefs as $ref) {
            $idElements = $ref->getElementsByTagName('ID');
            if ($idElements->length > 0 && $idElements->item(0)->nodeValue === 'QR') {
                $ref->parentNode->removeChild($ref);
                break;
            }
        }

        // Use C14N canonicalization
        return $doc->documentElement->C14N(false, false);
    }

    /**
     * Create ECDSA signature over the invoice hash.
     *
     * ZATCA signs the binary invoice hash directly with ECDSA.
     *
     * @param  PrivateKey  $privateKey
     */
    protected function createSignature(string $invoiceHashBinary, $privateKey): string
    {
        // Sign the binary hash directly (phpseclib handles the signing)
        $signature = $privateKey->sign($invoiceHashBinary);

        return base64_encode($signature);
    }

    /**
     * Replace the invoice's ds:Signature placeholder with the complete signature XML.
     */
    protected function insertSignature(string $xml, string $signatureXml): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $doc->loadXML($xml);

        // Replace the ds:Signature placeholder
        $result = $doc->saveXML();

        $pattern = '/<ds:Signature[^>]*Id="signature"[^>]*>.*?<\/ds:Signature>|<ds:Signature[^>]*Id="signature"[^>]*\/>/s';

        $result = preg_replace($pattern, $signatureXml, $result, 1);

        if ($result === null) {
            throw ZatcaException::withContext('Failed to insert signature into XML');
        }

        return $result;
    }

    /**
     * Compute SignedProperties hash following SDK approach.
     *
     * The Python SDK (zatca_erpgulf) builds the SignedProperties XML with
     * specific whitespace/indentation and then hashes it.
     *
     * CRITICAL: The whitespace MUST match EXACTLY what the Python SDK produces
     * in sign_invoice_first.py lines 785-801 (signxml_modify function).
     *
     * Indentation pattern (spaces from start of line):
     * - SignedProperties: 0 spaces (starts at column 1)
     * - SignedSignatureProperties: 36 spaces
     * - SigningTime, SigningCertificate: 40 spaces
     * - Cert: 44 spaces
     * - CertDigest, IssuerSerial: 48 spaces
     * - DigestMethod, DigestValue, X509IssuerName, X509SerialNumber: 52 spaces
     */
    protected function computeSignedPropertiesHashSdkStyle(string $signingTime, CertificateReference $certificate): string
    {
        // Build SignedProperties XML with EXACT whitespace matching Python SDK
        // sign_invoice_first.py signxml_modify function lines 785-801
        // Using heredoc to preserve exact whitespace
        $signedPropsXml = '<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>'.$signingTime.'</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$certificate->digest.'</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$certificate->issuer.'</ds:X509IssuerName>
                                                    <ds:X509SerialNumber xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$certificate->serial.'</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>';

        // Hash: base64(hex(sha256(xml)))
        $hashHex = hash('sha256', $signedPropsXml);

        return base64_encode($hashHex);
    }

    /**
     * Build the opening of the ds:Signature element through its ds:SignedInfo, in the Python SDK's standard indentation.
     */
    protected function signedInfoXml(string $invoiceHash, string $signedPropsHash): string
    {
        return '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
              <ds:SignedInfo>
                <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
                <ds:Reference Id="invoiceSignedData" URI="">
                  <ds:Transforms>
                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                      <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>
                    </ds:Transform>
                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                      <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>
                    </ds:Transform>
                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                      <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath>
                    </ds:Transform>
                    <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                  </ds:Transforms>
                  <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                  <ds:DigestValue>'.$invoiceHash.'</ds:DigestValue>
                </ds:Reference>
                <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
                  <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                  <ds:DigestValue>'.$signedPropsHash.'</ds:DigestValue>
                </ds:Reference>
              </ds:SignedInfo>';
    }

    /**
     * Build the ds:SignatureValue and the ds:KeyInfo that carries the signing certificate.
     */
    protected function keyInfoXml(string $signatureValue, string $certContent): string
    {
        return '              <ds:SignatureValue>'.$signatureValue.'</ds:SignatureValue>
              <ds:KeyInfo>
                <ds:X509Data>
                  <ds:X509Certificate>'.$certContent.'</ds:X509Certificate>
                </ds:X509Data>
              </ds:KeyInfo>';
    }

    /**
     * Build the ds:Object holding the XAdES qualifying properties, and close the ds:Signature element.
     *
     * The SignedProperties section uses indentation matching the Python SDK's
     * structuring_signedxml() function which adjusts to specific column positions:
     * - Column 29 (28 spaces): QualifyingProperties
     * - Column 33 (32 spaces): SignedProperties
     * - Column 37 (36 spaces): SignedSignatureProperties
     * - Column 41 (40 spaces): SigningTime, SigningCertificate
     * - Column 45 (44 spaces): Cert
     * - Column 49 (48 spaces): CertDigest, IssuerSerial
     * - Column 53 (52 spaces): DigestMethod, DigestValue, X509IssuerName, X509SerialNumber
     */
    protected function signatureObjectXml(string $signingTime, CertificateReference $certificate): string
    {
        return '              <ds:Object>
                            <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                <xades:SignedProperties Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>'.$signingTime.'</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue>'.$certificate->digest.'</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName>'.$certificate->issuer.'</ds:X509IssuerName>
                                                    <ds:X509SerialNumber>'.$certificate->serial.'</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>
                            </xades:QualifyingProperties>
              </ds:Object>
            </ds:Signature>';
    }

    /**
     * Extract certificate information.
     *
     * ZATCA requires:
     * - X509IssuerName in RFC 2253 canonical format (reversed, using phpseclib)
     * - X509SerialNumber as decimal integer
     * - Certificate digest: SHA256 of raw certificate content (base64 string without headers)
     *
     * Reference: php-zatca-xml-main/src/Helpers/Certificate.php getCertHash() method
     */
    protected function extractCertificateInfo(string $certificatePem): CertificateReference
    {
        // Get certificate content without PEM headers (raw base64 certificate)
        // This is what the reference package uses as "rawCertificate"
        $rawCertificate = $this->certificateBody($certificatePem);

        // Certificate digest - SHA256 of raw certificate string
        // Reference: base64_encode(hash('sha256', $this->rawCertificate)) - NO binary flag!
        // hash() without binary flag returns hex string, which is then base64 encoded
        $digest = base64_encode(hash('sha256', $rawCertificate));

        // Use phpseclib X509 for issuer and serial number
        $x509 = new X509;
        $certData = $x509->loadX509($certificatePem);

        // Get issuer DN string and format it per ZATCA requirements
        // Reference: php-zatca-xml-main/src/Helpers/Certificate.php getFormattedIssuer()
        $issuerDn = $x509->getIssuerDN(X509::DN_STRING);
        $issuerString = $this->formatIssuerDN($issuerDn);

        // Get serial number as decimal string
        // Reference: $this->cert->getCurrentCert()['tbsCertificate']['serialNumber']->toString()
        $serialNumber = '';
        if ($certData && isset($certData['tbsCertificate']['serialNumber'])) {
            $serialNumber = $certData['tbsCertificate']['serialNumber']->toString();
        }

        return new CertificateReference($digest, $issuerString, $serialNumber);
    }

    /**
     * The certificate's base64 body: the PEM without its header, footer and line breaks.
     */
    protected function certificateBody(string $certificatePem): string
    {
        return str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $certificatePem
        );
    }

    /**
     * Format issuer DN string for ZATCA.
     *
     * The ZATCA SDK (Python) uses cert.issuer.rfc4514_string() and then
     * joins with ", " (comma + space) separator.
     *
     * phpseclib's DN_STRING format returns the DN in correct order.
     * We need to ensure ", " separators are preserved (not stripped).
     */
    protected function formatIssuerDN(string $issuerDn): string
    {
        // ZATCA expects ", " (comma + space) format per RFC 4514
        // phpseclib DN_STRING already has this format - return as-is
        return $issuerDn;
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
        // Get digest value
        $storedDigest = $this->storedInvoiceDigest($signedXml);

        // Get signature value
        $signatureValue = $this->getSignatureValue($signedXml);

        if (empty($signatureValue) || $storedDigest === null) {
            return false;
        }

        // Calculate digest of invoice
        $calculatedDigest = $this->generateInvoiceHash($signedXml);

        // Compare digests
        if ($storedDigest !== $calculatedDigest) {
            return false;
        }

        return $this->verifyDigestSignature($storedDigest, $signatureValue, $publicKeyPem);
    }

    /**
     * The invoice digest the signature's invoiceSignedData reference records, or null when it has none.
     */
    protected function storedInvoiceDigest(string $signedXml): ?string
    {
        $doc = new DOMDocument;
        $doc->loadXML($signedXml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);

        $digestNodes = $xpath->query('//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue');

        if (! $digestNodes || $digestNodes->length === 0) {
            return null;
        }

        return $digestNodes->item(0)->nodeValue;
    }

    /**
     * Whether the public key verifies the ECDSA signature over the invoice digest.
     */
    protected function verifyDigestSignature(string $storedDigest, string $signatureValue, string $publicKeyPem): bool
    {
        // Verify signature
        try {
            /** @var PublicKey $publicKey */
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
