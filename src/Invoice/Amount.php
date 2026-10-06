<?php

namespace Corecave\Zatca\Invoice;

use Corecave\Zatca\Exceptions\InvoiceException;
use Stringable;

/**
 * An amount of money exactly as the caller priced it: whole units, a point and two decimals.
 *
 * The package never works an amount out. It writes the text it was given, and reads it in whole hundredths
 * only to compare two amounts.
 */
final class Amount implements Stringable
{
    private const string PATTERN = '/^(0|[1-9]\d{0,14})\.(\d{2})$/';

    private function __construct(
        public readonly string $value,
        public readonly int $hundredths,
    ) {}

    /**
     * @throws InvoiceException when the text is not whole units, a point and two decimals
     */
    public static function of(string $amount): self
    {
        if (preg_match(self::PATTERN, $amount, $parts) !== 1) {
            throw InvoiceException::invalidAmount($amount);
        }

        return new self($amount, (int) $parts[1] * 100 + (int) $parts[2]);
    }

    public function isZero(): bool
    {
        return $this->hundredths === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->hundredths > $other->hundredths;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
