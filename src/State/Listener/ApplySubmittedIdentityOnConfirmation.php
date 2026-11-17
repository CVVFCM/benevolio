<?php

declare(strict_types=1);

namespace App\State\Listener;

use App\Entity\Declaration;
use App\State\DeclarationState;
use Finite\Event\PostTransitionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Copies the name and address a declaration states onto its volunteer — when the
 * declaration is confirmed, and not a moment before.
 *
 * The public form is anonymous and recognises a returning volunteer by email alone.
 * Until a submission is confirmed, nothing shows that whoever typed it owns that
 * mailbox, so what they typed stays on the declaration (Declaration::$submittedAddress
 * and its neighbours). Confirmation is the proof: the volunteer opened the link, or an
 * admin vouched for them by hand. Both go through the `confirm` transition, which is
 * why this is a listener on it rather than a line in each caller — the reasoning of
 * DeclarationConfirmationCascade, next to which this sits.
 *
 * It runs inside the transaction DeclarationConfirmer already opens.
 */
final readonly class ApplySubmittedIdentityOnConfirmation
{
    #[AsEventListener(event: PostTransitionEvent::class)]
    public function __invoke(PostTransitionEvent $event): void
    {
        $declaration = $event->getObject();

        if (!$declaration instanceof Declaration) {
            return;
        }

        if (DeclarationState::TRANSITION_CONFIRM !== $event->getTransition()->getName()) {
            return;
        }

        if ($declaration->statesThePersonsIdentity()) {
            return;
        }

        $declaration->getPerson()->updateIdentity(
            $declaration->getSubmittedFirstName(),
            $declaration->getSubmittedLastName(),
            $declaration->getSubmittedAddress(),
        );
    }
}
