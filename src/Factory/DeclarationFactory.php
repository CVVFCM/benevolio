<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Declaration;
use App\Entity\Organization;
use App\Entity\Person;
use App\State\DeclarationState;
use App\ValueObject\Address;
use DateTimeImmutable;
use Finite\StateMachine;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\memoize;

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

    /**
     * A submission stating a name and address other than its volunteer's — what a
     * returning volunteer who moved produces, and what a stranger typing their email
     * produces too. Without this state a declaration states what the Person says.
     */
    public function stating(string $firstName, string $lastName, Address $address): self
    {
        return $this->afterInstantiate(
            static function (Declaration $declaration) use ($firstName, $lastName, $address): void {
                $declaration->declareIdentity($firstName, $lastName, $address);
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
        // Memoized, not a bare factory: Foundry creates from a factory every time it
        // meets one, so handing OrganizationFactory::new() to both keys below filed
        // the declaration under one association and its volunteer under another.
        // Lazy, so a caller going through for() or forPerson() creates no stray row.
        $organization = memoize(static fn (): Organization => OrganizationFactory::createOne());

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
