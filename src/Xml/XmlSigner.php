<?php

namespace Corecave\Zatca\Xml;

use DOMDocument;
use DOMXPath;
use phpseclib3\Crypt\EC;

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

    private const NS_SBC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

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
        $certContent = str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $certificatePem
        );

        // Generate signing time
        $signingTime = gmdate('Y-m-d\TH:i:s');

        // Build and insert the complete signature
        $signedXml = $this->buildSignedInvoice(
            $xml,
            $invoiceHash,
            $privateKey,
            $certContent,
            $certInfo,
            $signingTime
        );

        return $signedXml;
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
     */
    protected function createSignature(string $invoiceHashBinary, $privateKey): string
    {
        // Sign the binary hash directly (phpseclib handles the signing)
        $signature = $privateKey->sign($invoiceHashBinary);

        return base64_encode($signature);
    }

    /**
     * Build complete signed invoice following ZATCA SDK approach.
     *
     * The SDK approach:
     * 1. Insert UBL extensions template with placeholders
     * 2. Populate certificate values (digest, issuer, serial, signing time)
     * 3. Extract SignedProperties node as XML and hash it
     * 4. Sign the invoice hash
     * 5. Populate signature and hash values
     *
     * @param  \phpseclib3\Crypt\EC\PrivateKey  $privateKey
     */
    protected function buildSignedInvoice(
        string $xml,
        string $invoiceHash,
        $privateKey,
        string $certContent,
        array $certInfo,
        string $signingTime
    ): string {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $doc->loadXML($xml);

        // Sign the binary invoice hash directly
        $invoiceHashBinary = base64_decode($invoiceHash);
        $signatureValue = $this->createSignature($invoiceHashBinary, $privateKey);

        // Compute certificate hash - same as SDK: base64(hex(sha256(cert)))
        $certHash = $this->computeCertificateHash($certContent);

        // Compute SignedProperties hash following SDK approach
        // SDK extracts node from XML after populating values, then hashes it
        $signedPropsHash = $this->computeSignedPropertiesHashSdkStyle(
            $signingTime,
            $certHash,
            $certInfo['issuer'],
            $certInfo['serial']
        );

        // Build the complete signature XML
        $signatureXml = $this->buildSignatureXml(
            $invoiceHash,
            $signedPropsHash,
            $signatureValue,
            $certContent,
            $certHash,
            $certInfo['issuer'],
            $certInfo['serial'],
            $signingTime
        );

        // Replace the ds:Signature placeholder
        $result = $doc->saveXML();

        $pattern = '/<ds:Signature[^>]*Id="signature"[^>]*>.*?<\/ds:Signature>|<ds:Signature[^>]*Id="signature"[^>]*\/>/s';
        $replacement = $signatureXml;

        $result = preg_replace($pattern, $replacement, $result, 1);

        if ($result === null) {
            throw new \RuntimeException('Failed to insert signature into XML');
        }

        return $result;
    }

    /**
     * Compute certificate hash following SDK approach.
     *
     * SDK: base64(hex(sha256(certBytes)))
     * Where certBytes is the UTF-8 bytes of the base64 certificate string.
     */
    protected function computeCertificateHash(string $certBase64): string
    {
        // Hash the certificate bytes, get hex string, then base64 encode
        $hashHex = hash('sha256', $certBase64);

        return base64_encode($hashHex);
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
    protected function computeSignedPropertiesHashSdkStyle(
        string $signingTime,
        string $certDigest,
        string $issuer,
        string $serial
    ): string {
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
                                                    <ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$certDigest.'</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$issuer.'</ds:X509IssuerName>
                                                    <ds:X509SerialNumber xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$serial.'</ds:X509SerialNumber>
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
     * Build the complete ds:Signature element as XML string.
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
    protected function buildSignatureXml(
        string $invoiceHash,
        string $signedPropsHash,
        string $signatureValue,
        string $certContent,
        string $certDigest,
        string $issuer,
        string $serial,
        string $signingTime
    ): string {
        // Build signature XML matching Python SDK structure
        // The ds:Signature section uses standard indentation
        // The xades:* section uses specific column positions from structuring_signedxml()
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
              </ds:SignedInfo>
              <ds:SignatureValue>'.$signatureValue.'</ds:SignatureValue>
              <ds:KeyInfo>
                <ds:X509Data>
                  <ds:X509Certificate>'.$certContent.'</ds:X509Certificate>
                </ds:X509Data>
              </ds:KeyInfo>
              <ds:Object>
                            <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                <xades:SignedProperties Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>'.$signingTime.'</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue>'.$certDigest.'</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName>'.$issuer.'</ds:X509IssuerName>
                                                    <ds:X509SerialNumber>'.$serial.'</ds:X509SerialNumber>
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
    protected function extractCertificateInfo(string $certificatePem): array
    {
        // Get certificate content without PEM headers (raw base64 certificate)
        // This is what the reference package uses as "rawCertificate"
        $rawCertificate = str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $certificatePem
        );

        // Certificate digest - SHA256 of raw certificate string
        // Reference: base64_encode(hash('sha256', $this->rawCertificate)) - NO binary flag!
        // hash() without binary flag returns hex string, which is then base64 encoded
        $digest = base64_encode(hash('sha256', $rawCertificate));

        // Use phpseclib X509 for issuer and serial number
        $x509 = new \phpseclib3\File\X509;
        $certData = $x509->loadX509($certificatePem);

        // Get issuer DN string and format it per ZATCA requirements
        // Reference: php-zatca-xml-main/src/Helpers/Certificate.php getFormattedIssuer()
        $issuerDn = $x509->getIssuerDN(\phpseclib3\File\X509::DN_STRING);
        $issuerString = $this->formatIssuerDN($issuerDn);

        // Get serial number as decimal string
        // Reference: $this->cert->getCurrentCert()['tbsCertificate']['serialNumber']->toString()
        $serialNumber = '';
        if ($certData && isset($certData['tbsCertificate']['serialNumber'])) {
            $serialNumber = $certData['tbsCertificate']['serialNumber']->toString();
        }

        return [
            'digest' => $digest,
            'issuer' => $issuerString,
            'serial' => $serialNumber,
        ];
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
            /** @var \phpseclib3\Crypt\EC\PublicKey $publicKey */
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
