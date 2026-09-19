<?php

namespace Corecave\Zatca\Certificate;

/**
 * A certificate, its private key and the secret ZATCA pairs with it.
 */
class CertificateCredentials
{
    public function __construct(
        public readonly string $certificate,
        public readonly string $privateKey,
        public readonly string $secret,
    ) {}
}
