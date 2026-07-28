<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Declaration;
use App\Entity\Organization;
use App\Entity\Person;
use App\State\DeclarationState;
use DateTimeImmutable;
use Finite\StateMachine;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Declaration>
 */
final class DeclarationFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly StateMachine $stateMachine,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return Declaration::class;
    }

    /**
     * A declaration and its person must share a tenant, and nothing in the mapping
     * enforces that — so this is the way to build one for a known organization.
     */
    public function for(Organization $organization): self
    {
        return $this->with([
            'organization' => $organization,
            'person' => PersonFactory::new()->with(['organization' => $organization]),
        ]);
    }

    /**
     * A declaration the volunteer has confirmed — the normal starting point for
     * anything the back-office is meant to act on.
     *
     * Goes through the state machine rather than writing the column, so the
     * transition rules are exercised exactly as they are in production.
     */
    public function confirmed(): self
    {
        return $this->afterPersist(
            function (Declaration $declaration): void {
                $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_CONFIRM);
                $declaration->markConfirmed(new DateTimeImmutable());
            },
        );
    }

    public function forPerson(Person $person): self
    {
        return $this->with([
            'organization' => $person->getOrganization(),
            'person' => $person,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $organization = OrganizationFactory::new();

        return [
            'organization' => $organization,
            'person' => PersonFactory::new()->with(['organization' => $organization]),
            // Both statements are mandatory in the real form, so fixtures must not
            // produce a declaration that could never have been submitted.
            'accuracyAttested' => true,
            'expensesWaived' => true,
        ];
    }
}
