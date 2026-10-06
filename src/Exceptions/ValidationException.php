<?php

namespace Corecave\Zatca\Exceptions;

class ValidationException extends ZatcaException
{
    protected array $errors = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $errors = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = ['errors' => $errors];
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
        return new static($message, errors: $errors);
    }

    /**
     * A QR code whose TLV encoding cannot be decoded.
     */
    public static function malformedTlv(string $reason): static
    {
        return new static('The QR code cannot be decoded', errors: [$reason]);
    }

    /**
     * Check if there are any errors.
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
