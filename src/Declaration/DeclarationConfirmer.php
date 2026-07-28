<?php

declare(strict_types=1);

namespace App\Declaration;

use App\Entity\Declaration;
use App\Repository\DeclarationRepository;
use App\State\DeclarationState;
use Doctrine\ORM\EntityManagerInterface;
use Finite\StateMachine;
use Psr\Clock\ClockInterface;

/**
 * Redeems a confirmation link.
 *
 * Separate from the controller so the outcomes are decided in one place and can be
 * tested without HTTP. Returns a result rather than throwing, because three of the
 * four cases are ordinary things that happen to real people — only an unknown
 * token is exceptional, and that is the controller's 404.
 */
final readonly class DeclarationConfirmer
{
    public function __construct(
        private DeclarationRepository $declarations,
        private StateMachine $stateMachine,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{Declaration, DeclarationConfirmationResult}|null null when no
     *                                                                declaration matches the token at all
     */
    public function confirm(string $token): ?array
    {
        $declaration = $this->declarations->findOneByConfirmationToken($token);

        if (null === $declaration) {
            // No such token, for this association at least. The controller turns
            // this into a 404. A *used* token still resolves — it is kept on the
            // row precisely so a repeat click lands on the branch below.
            return null;
        }

        if ($declaration->isConfirmed()) {
            return [$declaration, DeclarationConfirmationResult::ALREADY_CONFIRMED];
        }

        $now = $this->clock->now();

        if ($declaration->isConfirmationTokenExpired($now)) {
            // The token stays on the row: the declaration is still there, and a
            // future "resend" feature will need something to attach to.
            return [$declaration, DeclarationConfirmationResult::EXPIRED];
        }

        $this->entityManager->wrapInTransaction(function () use ($declaration, $now): void {
            $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_CONFIRM);
            $declaration->markConfirmed($now);
        });

        return [$declaration, DeclarationConfirmationResult::CONFIRMED];
    }
}
