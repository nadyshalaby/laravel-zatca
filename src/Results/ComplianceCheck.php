<?php

namespace Corecave\Zatca\Results;

use Exception;

/**
 * One sample's compliance check: the document it stood for, and ZATCA's status with its messages, or
 * the failure that kept the sample from an answer.
 */
final readonly class ComplianceCheck
{
    /**
     * @param  array{type: string, subtype: string}  $sample
     * @param  list<array{message: string, code: string}>  $errors
     */
    public function __construct(
        public array $sample,
        public ?string $status,
        public array $errors = [],
        public ?Exception $failure = null,
    ) {}

    /**
     * Whether ZATCA passed the sample: a warning or an error does not.
     */
    public function isPassed(): bool
    {
        return $this->failure === null && strtoupper((string) $this->status) === 'PASS';
    }
}
