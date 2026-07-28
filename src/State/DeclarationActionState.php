<?php

declare(strict_types=1);

namespace App\State;

use Finite\State;
use Finite\Transition\Transition;

/**
 * Lifecycle of one declared contribution line.
 *
 * There is no draft state: until the volunteer finishes the multi-step form the
 * declaration exists only in their session, never in the database.
 *
 * Behaviour lives on the enum as methods rather than as state tests scattered
 * through the domain — the finite 2 idiom.
 */
enum DeclarationActionState: string implements State
{
    case SUBMITTED = 'submitted';
    case VALIDATED = 'validated';
    case REFUSED = 'refused';

    public const string TRANSITION_VALIDATE = 'validate';
    public const string TRANSITION_REFUSE = 'refuse';

    /**
     * @return list<Transition>
     */
    public static function getTransitions(): array
    {
        return [
            new Transition(self::TRANSITION_VALIDATE, [self::SUBMITTED], self::VALIDATED),
            new Transition(self::TRANSITION_REFUSE, [self::SUBMITTED], self::REFUSED),
        ];
    }

    /** A treasurer has ruled on this line, one way or the other. */
    public function isDecided(): bool
    {
        return self::SUBMITTED !== $this;
    }

    /** Only an undecided line may still be corrected. */
    public function isEditable(): bool
    {
        return self::SUBMITTED === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Soumise',
            self::VALIDATED => 'Validée',
            self::REFUSED => 'Refusée',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::SUBMITTED => 'warning',
            self::VALIDATED => 'success',
            self::REFUSED => 'danger',
        };
    }
}
