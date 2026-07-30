<?php

declare(strict_types=1);

namespace App\State\Listener;

use App\Entity\Declaration;
use App\Message\IssueReceipt;
use App\State\DeclarationState;
use Finite\Event\PostTransitionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks for a CERFA when a declaration is validated.
 *
 * On the transition rather than at the call site, like
 * App\State\Listener\DeclarationConfirmationCascade — a declaration reaching VALIDATED is
 * the fact that matters, and anything that ever validates one should produce a receipt
 * without having to remember to.
 *
 * `DeclarationTransitionGuard` blocks the whole-declaration verdict until every line is
 * validated, so arriving here already means the treasurer ruled on all of them. A mixed
 * basket never reaches VALIDATED and so never gets a receipt, which is the accepted
 * consequence of a single global verdict.
 *
 * Dispatching rather than doing the work: generation talks to Gotenberg and object
 * storage, and a state-machine listener is the wrong place to hold that. The message runs
 * synchronously today — see config/packages/messenger.yaml — so the treasurer still sees
 * the outcome on the page they are on.
 */
final readonly class ReceiptOnValidation
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener(event: PostTransitionEvent::class)]
    public function __invoke(PostTransitionEvent $event): void
    {
        $declaration = $event->getObject();

        // The event fires for DeclarationAction transitions too, including the ones the
        // decider applies to every line just before this.
        if (!$declaration instanceof Declaration) {
            return;
        }

        if (DeclarationState::TRANSITION_VALIDATE !== $event->getTransition()->getName()) {
            return;
        }

        $this->bus->dispatch(new IssueReceipt($declaration->getId()));
    }
}
