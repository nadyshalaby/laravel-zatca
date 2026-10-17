<?php

namespace Corecave\Zatca\Hash;

/**
 * What ZATCA's answer changes on a recorded invoice besides its status, each only when given.
 */
class StatusUpdate
{
    /**
     * @param  array<string, mixed>|null  $zatcaResponse
     */
    public function __construct(
        public readonly ?array $zatcaResponse = null,
        public readonly ?string $signedXml = null,
        public readonly ?string $qrCode = null,
    ) {}
}
