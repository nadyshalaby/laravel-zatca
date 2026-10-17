<?php

namespace Corecave\Zatca\Xml;

/**
 * The signing certificate as the XAdES signed properties name it: its digest, issuer and serial number.
 */
class CertificateReference
{
    public function __construct(
        public readonly string $digest,
        public readonly string $issuer,
        public readonly string $serial,
    ) {}
}
