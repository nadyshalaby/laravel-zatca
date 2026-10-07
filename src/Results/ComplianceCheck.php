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
     * Whether ZATCA passed the sample: a pass, or a warning, since ZATCA reports or clears a document
     * whose only messages are warnings (REPORTED and CLEARED with the status WARNING in the sandbox,
     * 2026-10-07); an error does not.
     */
    public function isPassed(): bool
    {
        return $this->failure === null && in_array(strtoupper((string) $this->status), ['PASS', 'WARNING'], true);
    }
}
