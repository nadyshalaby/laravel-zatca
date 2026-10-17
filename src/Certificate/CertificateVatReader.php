<?php

namespace Corecave\Zatca\Certificate;

use Corecave\Zatca\Contracts\CertificateInterface;
use phpseclib3\File\X509;

/**
 * Reads the VAT number a certificate was issued for: the first UID attribute of its subject alternative names.
 */
class CertificateVatReader
{
    /**
     * The VAT number in the certificate, or null when it names none or names it in another string type.
     */
    public function read(CertificateInterface $certificate): ?string
    {
        $x509 = new X509;
        $certData = $x509->loadX509($certificate->getCertificatePem());

        // Find VAT in Subject Alternative Name extension
        if (! isset($certData['tbsCertificate']['extensions'])) {
            return null;
        }

        $attribute = $this->uidInExtensions($certData['tbsCertificate']['extensions']);

        return $attribute['value']['utf8String'] ?? null;
    }

    /**
     * The first UID attribute of the subject alternative name extensions, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function uidInExtensions(mixed $extensions): ?array
    {
        foreach ($extensions as $ext) {
            if (isset($ext['extnId']) && $ext['extnId'] === 'id-ce-subjectAltName') {
                $attribute = $this->uidInNames($ext['extnValue']);

                if ($attribute !== null) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    /**
     * The first UID attribute of the directory names among the alternative names, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function uidInNames(mixed $alternativeNames): ?array
    {
        foreach ($alternativeNames as $san) {
            if (isset($san['directoryName']['rdnSequence'])) {
                $attribute = $this->uidInSequence($san['directoryName']['rdnSequence']);

                if ($attribute !== null) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    /**
     * The first UID attribute of a directory name's relative distinguished names, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function uidInSequence(mixed $rdnSequence): ?array
    {
        foreach ($rdnSequence as $rdn) {
            foreach ($rdn as $attr) {
                if ($attr['type'] === '0.9.2342.19200300.100.1.1') {
                    return $attr;
                }
            }
        }

        return null;
    }
}
