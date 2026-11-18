<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What an App\Entity\Activity is: a gathering with a date, or standing work the
 * association carries out. The public form lists the two under separate headings.
 */
enum ActivityType: string
{
    case EVENT = 'event';
    case ACTION = 'action';

    public function label(): string
    {
        return match ($this) {
            self::EVENT => 'Événement',
            self::ACTION => 'Action',
        };
    }

    /** The heading of this type's group in the public form's select. */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::EVENT => 'Événements',
            self::ACTION => 'Actions',
        };
    }

    /**
     * @return array<string, self> label => case, the shape EasyAdmin's ChoiceField wants
     */
    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
