<?php

declare(strict_types=1);

namespace App\ValueObject;

use Stringable;

/**
 * A duration of donated time, in hundredths of an hour.
 *
 * Hundredths for the reason amounts are cents: the column is DECIMAL(5,2) and sums must
 * stay exact without ext-bcmath. A quarter of an hour is 25, never 0.25.
 */
final readonly class WorkHours implements Stringable
{
    public function __construct(
        public int $hundredths,
    ) {
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * From the DECIMAL(5,2) column as Doctrine hands it over: "7.50" → 750. String
     * arithmetic on the two parts, never a float.
     */
    public static function fromDecimal(string $decimal): self
    {
        $parts = explode('.', $decimal, 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';
        $negative = str_starts_with($whole, '-');

        $hundredths = abs((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return new self($negative ? -$hundredths : $hundredths);
    }

    public function plus(self $other): self
    {
        return new self($this->hundredths + $other->hundredths);
    }

    /** "7,50 h" */
    public function format(): string
    {
        return FrenchDecimal::format($this->hundredths, 2)."\u{a0}h";
    }
}
