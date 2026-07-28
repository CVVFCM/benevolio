<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The closed set of roles this application grants.
 *
 * Volunteers are deliberately absent: they have no account. They identify
 * themselves on the public declaration form with an email one-time code.
 */
enum Role: string
{
    /** Manages one organization: its volunteers, missions, rates and contributions. */
    case ADMIN = 'ROLE_ADMIN';

    /** Manages the platform itself: creates and configures organizations. */
    case SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrateur de l\'association',
            self::SUPER_ADMIN => 'Administrateur de la plateforme',
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

    /**
     * Used as the Assert\Choice callback on User::$roles.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
