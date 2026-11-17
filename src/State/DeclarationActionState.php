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
 * A line starts unconfirmed, in step with its Declaration: it is not *soumise* before
 * the volunteer has clicked anything. App\State\Listener\DeclarationConfirmationCascade
 * moves the lines when the declaration is confirmed.
 *
 * Behaviour is shared with DeclarationState through App\State\DeclarationLifecycle.
 */
enum DeclarationActionState: string implements State
{
    use DeclarationLifecycle;

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
}
