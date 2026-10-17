<?php

namespace Corecave\Zatca\Certificate;

use Carbon\Carbon;

/**
 * When a certificate was issued and expires, and whether it is switched on.
 */
class CertificateLifetime
{
    public function __construct(
        public readonly ?Carbon $issuedAt = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly bool $active = true,
    ) {}
}
