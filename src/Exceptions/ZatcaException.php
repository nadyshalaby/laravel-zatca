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
     * Create a new exception with context.
     */
    public static function withContext(string $message, array $context = []): static
    {
        $exception = new static($message);
        $exception->context = $context;

        return $exception;
    }
}
