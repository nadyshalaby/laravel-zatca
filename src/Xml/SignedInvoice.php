<?php

namespace Corecave\Zatca\Xml;

/**
 * An invoice's hash and its signed XML, ready to be sent to ZATCA.
 */
class SignedInvoice
{
    public function __construct(
        public readonly string $hash,
        public readonly string $signedXml,
    ) {}
}
