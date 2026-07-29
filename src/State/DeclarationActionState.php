<?php

declare(strict_types=1);

namespace App\State;

use Finite\State;
use Finite\Transition\Transition;

/**
 * Lifecycle of one declared contribution line.
 *
 *     awaiting_confirmation --confirm--> submitted --validate--> validated
 *                                                 --refuse----> refused
 *
 * There is no draft state: until the volunteer finishes the multi-step form the
 * declaration exists only in their session, never in the database.
 *
 * A line starts unconfirmed, in step with its Declaration. It used to start
 * SUBMITTED while the declaration it belonged to was still AWAITING_CONFIRMATION,
 * so every line claimed to be *soumise* before the volunteer had clicked anything.
 * App\State\Listener\DeclarationConfirmationCascade moves the lines when the
 * declaration is confirmed.
 *
 * Behaviour lives on the enum as methods rather than as state tests scattered
 * through the domain — the finite 2 idiom.
 */
enum DeclarationActionState: string implements State
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
     * A treasurer has ruled on this line, one way or the other.
     *
     * Spelled out rather than `SUBMITTED !== $this`, which it used to be: with a
     * state before SUBMITTED that shortcut called an unconfirmed line *decided*.
     * Mirrors App\State\DeclarationState::isDecided().
     */
    public function isDecided(): bool
    {
        return self::VALIDATED === $this || self::REFUSED === $this;
    }

    /** The volunteer has not clicked the confirmation link yet. */
    public function isAwaitingConfirmation(): bool
    {
        return self::AWAITING_CONFIRMATION === $this;
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
