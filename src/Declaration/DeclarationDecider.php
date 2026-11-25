<?php

declare(strict_types=1);

namespace App\Declaration;

use App\Declaration\Exception\DeclarationNotDecidableException;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Doctrine\ORM\EntityManagerInterface;
use Finite\StateMachine;

/**
 * Applies one verdict to a whole declaration: every line, then the declaration
 * itself.
 *
 * This is what the "Valider tout" button in the back-office calls. The order
 * matters — App\State\Listener\DeclarationTransitionGuard refuses the
 * declaration-level transition until every line already agrees with it.
 *
 * The mixed-basket case is refused UP FRONT rather than discovered halfway
 * through. Wrapping the loop in a transaction would roll the database back, but
 * the in-memory entities would already have been mutated, which is a nasty thing
 * to leave behind on a request that continues.
 */
final readonly class DeclarationDecider
{
    public function __construct(
        private StateMachine $stateMachine,
        private EntityManagerInterface $entityManager,
        private DeclarationNotifier $notifier,
    ) {
    }

    public function canValidateAll(Declaration $declaration): bool
    {
        return $this->canDecideAll($declaration, DeclarationActionState::REFUSED);
    }

    public function canRefuseAll(Declaration $declaration): bool
    {
        return $this->canDecideAll($declaration, DeclarationActionState::VALIDATED);
    }

    /**
     * @throws DeclarationNotDecidableException when a single verdict does not apply
     */
    public function validateAll(Declaration $declaration): void
    {
        $this->decideAll(
            $declaration,
            DeclarationActionState::REFUSED,
            DeclarationActionState::TRANSITION_VALIDATE,
            DeclarationState::TRANSITION_VALIDATE,
        );
    }

    /**
     * The counterpart of validateAll(), and the only way DeclarationState::REFUSED
     * is reachable: the guard blocks the declaration-level refusal until every
     * line is refused, which nothing else does in bulk.
     *
     * @throws DeclarationNotDecidableException when a single verdict does not apply
     */
    public function refuseAll(Declaration $declaration): void
    {
        $this->decideAll(
            $declaration,
            DeclarationActionState::VALIDATED,
            DeclarationActionState::TRANSITION_REFUSE,
            DeclarationState::TRANSITION_REFUSE,
        );
    }

    /**
     * Rules on one line, from its own row in the back office.
     *
     * @return bool false when the line has already been ruled on, or is not confirmed yet
     */
    public function validateLine(DeclarationAction $action): bool
    {
        return $this->decideLine($action, DeclarationActionState::TRANSITION_VALIDATE);
    }

    /**
     * @return bool false when the line has already been ruled on, or is not confirmed yet
     */
    public function refuseLine(DeclarationAction $action): bool
    {
        return $this->decideLine($action, DeclarationActionState::TRANSITION_REFUSE);
    }

    /**
     * The volunteer is told at the LAST line, whatever the declaration's own state: a mixed
     * basket never leaves `submitted`, and it is finished all the same.
     */
    private function decideLine(DeclarationAction $action, string $transition): bool
    {
        if (!$this->stateMachine->can($action, $transition)) {
            return false;
        }

        $this->stateMachine->apply($action, $transition);
        $this->entityManager->flush();

        $declaration = $action->getDeclaration();

        if (!$declaration->hasUndecidedAction()) {
            $this->notifier->notifyDecided($declaration);
        }

        return true;
    }

    /**
     * @param DeclarationActionState $conflicting the line verdict that rules out this bulk verdict
     */
    private function canDecideAll(Declaration $declaration, DeclarationActionState $conflicting): bool
    {
        // Awaiting confirmation is undecided but not actionable: the volunteer has
        // not clicked the link, so there is nothing to rule on yet. isDecided()
        // alone would let it through.
        if ($declaration->getState()->isAwaitingConfirmation()) {
            return false;
        }

        if ($declaration->getState()->isDecided() || $declaration->getActions()->isEmpty()) {
            return false;
        }

        foreach ($declaration->getActions() as $action) {
            if ($conflicting === $action->getState()) {
                return false;
            }
        }

        return true;
    }

    private function decideAll(
        Declaration $declaration,
        DeclarationActionState $conflicting,
        string $actionTransition,
        string $declarationTransition,
    ): void {
        if ($declaration->getState()->isAwaitingConfirmation()) {
            throw DeclarationNotDecidableException::awaitingConfirmation();
        }

        if ($declaration->getState()->isDecided()) {
            throw DeclarationNotDecidableException::alreadyDecided($declaration);
        }

        if ($declaration->getActions()->isEmpty()) {
            throw DeclarationNotDecidableException::hasNoAction();
        }

        if (!$this->canDecideAll($declaration, $conflicting)) {
            throw DeclarationNotDecidableException::linesDisagree();
        }

        // Every line ruled on one by one already told the volunteer, at the last of them:
        // the declaration's own verdict then changes nothing they have not read.
        $alreadyAnnounced = !$declaration->hasUndecidedAction();

        $this->entityManager->wrapInTransaction(function () use ($declaration, $actionTransition, $declarationTransition): void {
            foreach ($declaration->getActions() as $action) {
                // Lines already carrying this verdict have no transition left.
                if ($this->stateMachine->can($action, $actionTransition)) {
                    $this->stateMachine->apply($action, $actionTransition);
                }
            }

            $this->stateMachine->apply($declaration, $declarationTransition);
        });

        if (!$alreadyAnnounced) {
            $this->notifier->notifyDecided($declaration);
        }
    }
}
