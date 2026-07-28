<?php

declare(strict_types=1);

namespace App\State\Listener;

use App\Entity\Declaration;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Finite\Event\CanTransitionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the two state machines from diverging.
 *
 * Declaration has its own lifecycle rather than a status derived from its lines,
 * which means nothing structurally prevents a declaration from claiming to be
 * *validée* while one of its lines is still *soumise*. This guard is what
 * prevents it: it blocks the whole-declaration verdict until every line already
 * agrees with it.
 *
 * finite dispatches CanTransitionEvent through Symfony's event dispatcher (see
 * config/packages/finite.yaml), so this is an ordinary Symfony listener. The
 * event is fired for DeclarationAction transitions too, hence the instanceof.
 *
 * KNOWN CONSEQUENCE: a mixed outcome — some lines validated, some refused — has
 * no terminal state and stays *soumise*. That is the accepted trade-off of a
 * global verdict; see App\State\DeclarationState.
 */
final readonly class DeclarationTransitionGuard
{
    #[AsEventListener(event: CanTransitionEvent::class)]
    public function __invoke(CanTransitionEvent $event): void
    {
        $declaration = $event->getObject();

        if (!$declaration instanceof Declaration) {
            return;
        }

        $required = match ($event->getTransition()->getName()) {
            DeclarationState::TRANSITION_VALIDATE => DeclarationActionState::VALIDATED,
            DeclarationState::TRANSITION_REFUSE => DeclarationActionState::REFUSED,
            default => null,
        };

        if (null === $required) {
            return;
        }

        // hasEveryActionInState() is false for an empty collection, so a
        // declaration with no lines can never reach a verdict either.
        if (!$declaration->hasEveryActionInState($required)) {
            $event->blockTransition();
        }
    }
}
