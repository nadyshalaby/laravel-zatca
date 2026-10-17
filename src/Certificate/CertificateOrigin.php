<?php

namespace Corecave\Zatca\Certificate;

/**
 * The kind of CSID a certificate is, the request ZATCA issued it for and what ZATCA said about it.
 */
class CertificateOrigin
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $requestId = null,
        public readonly array $metadata = [],
    ) {}
}
