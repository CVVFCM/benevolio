<?php

declare(strict_types=1);

namespace App\ValueObject;

use Stringable;

use function sprintf;

/**
 * A barème figure, in MILLIÈMES d'euro per kilometre, and the bonus an electric vehicle
 * gets on it, in whole percent.
 *
 * Not cents: the published rates have three decimals — 0,529 €/km for 3 CV et moins —
 * and cents would round the law. The one unit in this codebase that is not cents,
 * because a rate is not an amount.
 */
final readonly class MileageRate implements Stringable
{
    public function __construct(
        public int $milliEurosPerKm,
        /** « Majoré de 20 % pour les véhicules électriques » — applied to the amount, not here. */
        public int $electricBonusPercent,
    ) {
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** "0,636 €/km" */
    public function format(): string
    {
        return FrenchDecimal::format($this->milliEurosPerKm, 3)."\u{a0}€/km";
    }

    /** "0,636 €/km, +20 % électrique" — for a page showing the whole rate. */
    public function formatWithElectricBonus(): string
    {
        return sprintf("%s, +%d\u{a0}%% électrique", $this->format(), $this->electricBonusPercent);
    }
}
