<?php

namespace Corecave\Zatca\Exceptions;

use Exception;

class ZatcaException extends Exception
{
    protected array $context = [];

    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the exception context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create a new exception with context.
     */
    public static function withContext(string $message, array $context = []): static
    {
        return new static($message, 0, null, $context);
    }
}
