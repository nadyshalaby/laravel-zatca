<?php

namespace Corecave\Zatca\Certificate;

use Stringable;

/**
 * The serial number of an EGS unit, written the way ZATCA reads it: 1-Company|2-Version|3-UUID.
 */
class EgsSerialNumber implements Stringable
{
    public function __construct(
        public readonly string $companyName,
        public readonly string $version,
        public readonly string $uuid,
    ) {}

    public function __toString(): string
    {
        return sprintf('1-%s|2-%s|3-%s', $this->companyName, $this->version, $this->uuid);
    }
}
