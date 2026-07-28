<?php

declare(strict_types=1);

namespace App\State;

use Finite\State;
use Finite\Transition\Transition;

/**
 * Lifecycle of a whole declaration.
 *
 * Deliberately a second state machine alongside DeclarationActionState rather
 * than a value derived from its lines. The two are kept from diverging by
 * App\State\Listener\DeclarationTransitionGuard, which refuses `validate` until
 * every line is validated and `refuse` until every line is refused.
 *
 * KNOWN CONSEQUENCE: a mixed outcome (some lines validated, some refused) has no
 * terminal state and stays SUBMITTED. That is the accepted trade-off of having a
 * global verdict; if it becomes a problem, the fix is a `partially_validated`
 * state or moving to a derived status, not weakening the guard.
 */
enum DeclarationState: string implements State
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

    public function isDecided(): bool
    {
        return self::SUBMITTED !== $this;
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
