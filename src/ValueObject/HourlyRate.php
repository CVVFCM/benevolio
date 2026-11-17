<?php

declare(strict_types=1);

namespace App\ValueObject;

use Stringable;

/**
 * What an hour of volunteering is worth, in cents per hour.
 */
final readonly class HourlyRate implements Stringable
{
    public function __construct(
        public int $centsPerHour,
    ) {
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** "12,00 €/h" */
    public function format(): string
    {
        return FrenchDecimal::format($this->centsPerHour, 2)."\u{a0}€/h";
    }
}
