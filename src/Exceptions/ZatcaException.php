<?php

namespace Corecave\Zatca\Exceptions;

use Exception;

class ZatcaException extends Exception
{
    protected array $context = [];

    /**
     * Get the exception context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create a new exception with context, and the exception that caused it where there is one.
     */
    public static function withContext(string $message, array $context = [], ?\Throwable $previous = null): static
    {
        $exception = new static($message, 0, $previous);
        $exception->context = $context;

        return $exception;
    }
}
