<?php

namespace Corecave\Zatca\Exceptions;

class ValidationException extends ZatcaException
{
    protected array $errors = [];

    public function __construct(string $message = '', array $errors = [], int $code = 0)
    {
        parent::__construct($message, $code, null, ['errors' => $errors]);
        $this->errors = $errors;
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create exception from validation errors array.
     */
    public static function fromErrors(array $errors, string $message = 'Invoice validation failed'): static
    {
        return new static($message, $errors);
    }

    /**
     * Check if there are any errors.
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
