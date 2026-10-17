<?php

namespace Corecave\Zatca\Results;

use Corecave\Zatca\Contracts\InvoiceInterface;

/**
 * An invoice sent to ZATCA and ZATCA's answer to it.
 */
class Submission
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly InvoiceInterface $invoice,
        public readonly array $response,
    ) {}
}
