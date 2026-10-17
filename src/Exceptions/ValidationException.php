<?php

namespace Corecave\Zatca\Exceptions;

class ValidationException extends ZatcaException
{
    /** @var array<array-key, string> */
    protected array $errors = [];

    /**
     * @param  array<array-key, string>  $errors
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $errors = []) // law-exception: PHP's Exception takes its first three, and the errors found are the fourth
    {
        parent::__construct($message, $code, $previous);
        $this->context = ['errors' => $errors];
        $this->errors = $errors;
    }

    /**
     * Get validation errors.
     *
     * @return array<array-key, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create exception from validation errors array.
     *
     * @param  array<array-key, string>  $errors
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
