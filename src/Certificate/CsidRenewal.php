<?php

namespace Corecave\Zatca\Certificate;

use Corecave\Zatca\Contracts\CertificateInterface;

/**
 * A production CSID renewal: the certificate it renews, the new CSR with its private key, and the OTP from the FATOORA portal.
 */
class CsidRenewal
{
    /**
     * @param  array{csr: string, private_key: string, public_key: string}  $csrData
     */
    public function __construct(
        public readonly CertificateInterface $certificate,
        public readonly array $csrData,
        public readonly ?string $otp,
    ) {}
}
