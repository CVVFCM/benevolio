<?php

declare(strict_types=1);

namespace App\State\Listener;

use App\Entity\Declaration;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Finite\Event\PostTransitionEvent;
use Finite\StateMachine;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves every line of a declaration to *soumise* when the volunteer confirms it.
 *
 * Both machines start in `awaiting_confirmation`, and only the declaration carries
 * the token — so without this the lines would sit unconfirmed forever while the
 * declaration they belong to had moved on. `DeclarationTransitionGuard` blocks a
 * verdict until the lines agree, which means a missed cascade would not corrupt
 * anything; it would simply make the declaration undecidable, silently.
 *
 * A listener rather than a loop in `DeclarationConfirmer` — unlike
 * `DeclarationDecider::decideAll()`, which cascades validate and refuse at the call
 * site. The reason is that confirmation has exactly one trigger today and may grow
 * another (a resend, an admin confirming by hand), and every one of them must
 * cascade. Being invisible at the call site is the price; this docblock and the note
 * in `AGENTS.md` are what pay it.
 *
 * It runs inside the transaction `DeclarationConfirmer` already opens, so the
 * declaration and its lines move together or not at all.
 */
final readonly class DeclarationConfirmationCascade
{
    public function __construct(
        private StateMachine $stateMachine,
    ) {
    }

    #[AsEventListener(event: PostTransitionEvent::class)]
    public function __invoke(PostTransitionEvent $event): void
    {
        $declaration = $event->getObject();

        // The event fires for DeclarationAction transitions too, including the ones
        // applied below — this is what stops it re-entering.
        if (!$declaration instanceof Declaration) {
            return;
        }

        if (DeclarationState::TRANSITION_CONFIRM !== $event->getTransition()->getName()) {
            return;
        }

        foreach ($declaration->getActions() as $action) {
            // A line added after its declaration was confirmed has no transition
            // left to make here; can() keeps that from throwing.
            if ($this->stateMachine->can($action, DeclarationActionState::TRANSITION_CONFIRM)) {
                $this->stateMachine->apply($action, DeclarationActionState::TRANSITION_CONFIRM);
            }
        }
    }
}
