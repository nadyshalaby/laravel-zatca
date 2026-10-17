<?php

namespace Corecave\Zatca\Exceptions;

use Exception;

/**
 * Every package exception takes Exception's own (message, code, previous) first, so the static
 * factories can build any of them with new static().
 *
 * @phpstan-consistent-constructor
 */
class ZatcaException extends Exception
{
    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * Get the exception context.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create a new exception with context, and the exception that caused it where there is one.
     *
     * @param  array<string, mixed>  $context
     */
    public static function withContext(string $message, array $context = [], ?\Throwable $previous = null): static
    {
        $exception = new static($message, 0, $previous);
        $exception->context = $context;

        return $exception;
    }
}
