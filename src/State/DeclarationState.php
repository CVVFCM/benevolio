<?php

declare(strict_types=1);

namespace App\State;

use Finite\State;
use Finite\Transition\Transition;

/**
 * Lifecycle of a whole declaration.
 *
 *     awaiting_confirmation --confirm--> submitted --validate--> validated
 *                                                  --refuse----> refused
 *
 * A declaration starts unconfirmed: it exists in the database, but the volunteer
 * has not yet clicked the link emailed to them. That click is double opt-in — it
 * also proves the address works, which is what a CERFA receipt will have to be
 * sent to.
 *
 * `validate` and `refuse` name SUBMITTED as their only source, so an unconfirmed
 * declaration cannot be decided: the state machine refuses it without a guard.
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
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';
    case SUBMITTED = 'submitted';
    case VALIDATED = 'validated';
    case REFUSED = 'refused';

    public const string TRANSITION_CONFIRM = 'confirm';
    public const string TRANSITION_VALIDATE = 'validate';
    public const string TRANSITION_REFUSE = 'refuse';

    /**
     * @return list<Transition>
     */
    public static function getTransitions(): array
    {
        return [
            new Transition(self::TRANSITION_CONFIRM, [self::AWAITING_CONFIRMATION], self::SUBMITTED),
            new Transition(self::TRANSITION_VALIDATE, [self::SUBMITTED], self::VALIDATED),
            new Transition(self::TRANSITION_REFUSE, [self::SUBMITTED], self::REFUSED),
        ];
    }

    /**
     * The volunteer has not clicked the link yet, so the association has nothing
     * to act on.
     */
    public function isAwaitingConfirmation(): bool
    {
        return self::AWAITING_CONFIRMATION === $this;
    }

    /**
     * A verdict has been reached. Not the same as "not submitted": an unconfirmed
     * declaration is undecided too, which is why callers wanting "can a verdict be
     * applied" must check both.
     */
    public function isDecided(): bool
    {
        return self::VALIDATED === $this || self::REFUSED === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::AWAITING_CONFIRMATION => 'En attente de confirmation',
            self::SUBMITTED => 'Soumise',
            self::VALIDATED => 'Validée',
            self::REFUSED => 'Refusée',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::AWAITING_CONFIRMATION => 'secondary',
            self::SUBMITTED => 'warning',
            self::VALIDATED => 'success',
            self::REFUSED => 'danger',
        };
    }
}
