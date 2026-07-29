<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Entity\Task;
use App\Enum\FiscalPower;
use App\Repository\TaskRepository;
use App\State\DeclarationActionState;
use DateTimeImmutable;
use Finite\StateMachine;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function assert;
use function sprintf;

/**
 * @extends PersistentObjectFactory<DeclarationAction>
 */
final class DeclarationActionFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly StateMachine $stateMachine,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return DeclarationAction::class;
    }

    /**
     * Attaches the action to a declaration, with a type from the SAME association.
     *
     * One of this or for() must be called: defaults() deliberately supplies neither
     * `declaration` nor `task`, because an action and its type have to share a
     * tenant and nothing in the mapping enforces it. An earlier version guessed in
     * defaults() and produced an orphan organization per action, because Foundry
     * evaluates the defaults whether or not the caller overrides them.
     */
    public function forDeclaration(Declaration $declaration): self
    {
        return $this->with([
            'declaration' => $declaration,
            'task' => $this->existingTypeFor($declaration->getOrganization()),
        ]);
    }

    /**
     * A line the volunteer has already confirmed, for attaching to a declaration
     * that is itself already confirmed.
     *
     * Needed because a line now starts in AWAITING_CONFIRMATION, and
     * App\State\Listener\DeclarationConfirmationCascade only moves the lines that
     * exist at the moment the declaration is confirmed. Attaching a line to an
     * already-confirmed declaration therefore leaves it unconfirmed forever — which
     * cannot happen in production, where DeclarationSubmitter writes every line
     * before the confirmation link is ever opened, but happens constantly in a
     * fixture or a test built in the convenient order.
     *
     * Goes through the state machine rather than writing the column, like
     * DeclarationFactory::confirmed().
     */
    public function confirmed(): self
    {
        return $this->afterPersist(
            function (DeclarationAction $action): void {
                $this->stateMachine->apply($action, DeclarationActionState::TRANSITION_CONFIRM);
            },
        );
    }

    /**
     * A line a treasurer has already ruled on, which is the only kind that reaches the
     * ledger — App\Accounting\LedgerBuilder lists validated lines and nothing else.
     *
     * Confirms first, because `validate` names SUBMITTED as its only source. Through the
     * state machine both times, so the transition rules are exercised exactly as in
     * production rather than by writing the column.
     */
    public function validated(): self
    {
        return $this->afterPersist(
            function (DeclarationAction $action): void {
                if ($this->stateMachine->can($action, DeclarationActionState::TRANSITION_CONFIRM)) {
                    $this->stateMachine->apply($action, DeclarationActionState::TRANSITION_CONFIRM);
                }

                $this->stateMachine->apply($action, DeclarationActionState::TRANSITION_VALIDATE);
            },
        );
    }

    /**
     * A declaration and an action for one organization, in one call.
     */
    public function for(Organization $organization): self
    {
        return $this->with([
            'declaration' => DeclarationFactory::new()->for($organization),
            'task' => $this->existingTypeFor($organization),
        ]);
    }

    public function withOwnVehicle(FiscalPower $fiscalPower = FiscalPower::FIVE_CV): self
    {
        return $this->with([
            'ownVehicle' => true,
            'fiscalPower' => $fiscalPower,
        ]);
    }

    public function onFoot(): self
    {
        return $this->with([
            'ownVehicle' => false,
            'fiscalPower' => null,
            'journeys' => 0,
            'distanceKm' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'title' => self::faker()->sentence(4),
            'description' => self::faker()->optional()->sentence(),
            // Firmly in the past, and far enough back that consecutiveDays cannot
            // push the end date past today — DeclarationAction refuses an action
            // that has not finished yet.
            'date' => new DateTimeImmutable(sprintf('-%d days', self::faker()->numberBetween(30, 300))),
            'consecutiveDays' => 1,
            // One-way kilometres and the number of one-way journeys: a return trip
            // is two journeys.
            'journeys' => 2,
            'distanceKm' => self::faker()->numberBetween(5, 80),
            'ownVehicle' => false,
            'fiscalPower' => null,
            // Quarter-hour granularity, which is what DECIMAL(5,2) is for.
            'workHours' => sprintf(
                '%d.%02d',
                self::faker()->numberBetween(1, 8),
                self::faker()->numberBetween(0, 3) * 25,
            ),
        ];
    }

    /**
     * Reuses one of the association's real types rather than inventing another, so
     * fixtures read like something a club would actually have.
     *
     * Queried with an explicit organization rather than through the repository's
     * findActive(): the tenant filter is OFF in CLI and test context, so a filtered
     * helper would happily hand back another association's row.
     */
    private function existingTypeFor(Organization $organization): Task
    {
        /** @var list<Task> $existing */
        $existing = $this->tasks->findBy(['organization' => $organization, 'active' => true]);

        if ([] === $existing) {
            return TaskFactory::new()->for($organization)->create();
        }

        // Spread across the list instead of always taking the first row, so a
        // fixture set does not end up with every action filed under one category.
        // Still reproducible: Foundry seeds faker per run and prints the seed.
        $picked = self::faker()->randomElement($existing);
        assert($picked instanceof Task);

        return $picked;
    }
}
