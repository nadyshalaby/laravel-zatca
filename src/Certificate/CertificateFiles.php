<?php

namespace Corecave\Zatca\Certificate;

/**
 * Where a certificate and its private key are kept on disk.
 */
class CertificateFiles
{
    public function __construct(
        public readonly string $certificatePath,
        public readonly string $privateKeyPath,
    ) {}
}
