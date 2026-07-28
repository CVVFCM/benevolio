<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What kind of event a volunteer contributed to.
 *
 * Closed set on purpose: these are the categories the association reports on.
 * Adding one is a deliberate act, not free-text drift.
 */
enum EventType: string
{
    case TRAVAUX = 'travaux';
    case REGATE = 'regate';
    case ENCADREMENT = 'encadrement';
    case ARBITRAGE = 'arbitrage';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::TRAVAUX => 'Travaux',
            self::REGATE => 'Régate',
            self::ENCADREMENT => 'Encadrement',
            self::ARBITRAGE => 'Arbitrage',
            self::AUTRE => 'Autre',
        };
    }

    /**
     * @return array<string, string> label => value, for form and EasyAdmin choices
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
