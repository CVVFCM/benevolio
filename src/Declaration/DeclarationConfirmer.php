<?php

declare(strict_types=1);

namespace App\Declaration;

use App\Declaration\Exception\DeclarationNotConfirmableException;
use App\Entity\Declaration;
use App\Entity\User;
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
     * What following the link would do, without doing it.
     *
     * The emailed URL is opened by more than its reader — mail scanners fetch every
     * link in a message — so a GET must change nothing. It shows a page with one
     * button, and that page needs to know which of the three it is looking at.
     *
     * @return array{Declaration, DeclarationConfirmationResult|null}|null null when no declaration
     *                                                                     matches the token at all; a null result when the
     *                                                                     declaration is still there to be confirmed
     */
    public function look(string $token): ?array
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

        if ($declaration->isConfirmationTokenExpired($this->clock->now())) {
            // The token stays on the row: the declaration is still there, and a
            // future "resend" feature will need something to attach to.
            return [$declaration, DeclarationConfirmationResult::EXPIRED];
        }

        return [$declaration, null];
    }

    /**
     * Confirms. Only ever called for the button's POST — see look().
     *
     * @return array{Declaration, DeclarationConfirmationResult}|null null when no
     *                                                                declaration matches the token at all
     */
    public function confirm(string $token): ?array
    {
        $found = $this->look($token);

        if (null === $found) {
            return null;
        }

        [$declaration, $result] = $found;

        if (null !== $result) {
            return [$declaration, $result];
        }

        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($declaration, $now): void {
            $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_CONFIRM);
            $declaration->markConfirmed($now);
        });

        return [$declaration, DeclarationConfirmationResult::CONFIRMED];
    }

    /**
     * Confirms on the volunteer's behalf, from the back office, when the mail
     * carrying their link never arrived.
     *
     * Not a normal path. It skips the one thing the double opt-in proves — that
     * the address works and that the volunteer meant to send this — so it records
     * who vouched instead (Declaration::$confirmedBy). The token's expiry is
     * deliberately ignored: a mail that never arrived is exactly the case where
     * the link runs out before anyone notices.
     *
     * Goes through the same `confirm` transition as the link, so the lines move
     * with it (DeclarationConfirmationCascade) exactly as they would on a click.
     *
     * @throws DeclarationNotConfirmableException when the declaration is not awaiting confirmation
     */
    public function confirmManually(Declaration $declaration, User $by): void
    {
        if (!$this->canConfirmManually($declaration)) {
            throw DeclarationNotConfirmableException::notAwaitingConfirmation($declaration);
        }

        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($declaration, $by, $now): void {
            $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_CONFIRM);
            $declaration->markConfirmedManually($by, $now);
        });
    }

    /**
     * Confirms a declaration filed by a volunteer logged in to their space — see
     * DeclarationSubmitter::submitAs(). The login link proved the address; nobody vouches
     * for anyone, so it is recorded as the volunteer's own confirmation.
     *
     * Runs inside the caller's transaction.
     */
    public function confirmBySubmission(Declaration $declaration): void
    {
        $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_CONFIRM);
        $declaration->markConfirmed($this->clock->now());
        $this->entityManager->flush();
    }

    public function canConfirmManually(Declaration $declaration): bool
    {
        return $declaration->getState()->isAwaitingConfirmation();
    }
}
