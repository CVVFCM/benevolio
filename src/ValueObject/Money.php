<?php

declare(strict_types=1);

namespace App\ValueObject;

use Stringable;

/**
 * An amount, in whole euro cents.
 *
 * Cents and never a float: an amount is summed and multiplied on the way to a tax
 * receipt, and ext-bcmath is not installed. The arithmetic that makes an amount —
 * App\Accounting\ContributionValuator — stays on integers and builds one of these at
 * the end; this is what the domain hands to everything that reads or prints it.
 */
final readonly class Money implements Stringable
{
    public function __construct(
        public int $cents,
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

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function isZero(): bool
    {
        return 0 === $this->cents;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    /**
     * "1 234,56 €": grouped, and a no-break space before the sign so it never wraps away.
     */
    public function format(): string
    {
        return FrenchDecimal::format($this->cents, 2)."\u{a0}€";
    }

    /**
     * "1234,56": no sign and no grouping, for the CERFA's amount box, where « euros » is
     * already printed beside it.
     */
    public function formatBare(): string
    {
        return FrenchDecimal::format($this->cents, 2, grouped: false);
    }
}
