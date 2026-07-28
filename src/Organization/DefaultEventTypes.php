<?php

declare(strict_types=1);

namespace App\Organization;

use App\Entity\EventType;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a newly created association a usable list of event types.
 *
 * Without this a fresh organization has an empty list, and its public declaration
 * form offers no choice at all — broken on arrival. The association can rename,
 * deactivate or extend the list afterwards; these are a starting point, not a
 * fixed vocabulary.
 *
 * CALL SITES ARE EXPLICIT, and there are two:
 * App\Controller\Platform\OrganizationCrudController::persistEntity() and
 * App\Factory\OrganizationFactory. A Doctrine postPersist listener would be one
 * place instead of two, but persisting entities from inside postPersist needs a
 * second flush and is fragile. The cost of the choice is that a third way of
 * creating an Organization would silently skip seeding — hence the test in
 * tests/Organization/DefaultEventTypesTest.
 */
final readonly class DefaultEventTypes
{
    /**
     * The five categories the application shipped with when this was an enum.
     *
     * @var list<string>
     */
    public const array NAMES = [
        'Travaux',
        'Régate',
        'Encadrement',
        'Arbitrage',
        'Autre',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Persists the starter list. Does not flush — the caller owns the transaction,
     * which for the platform CRUD is EasyAdmin's own.
     *
     * @return list<EventType>
     */
    public function createFor(Organization $organization): array
    {
        $created = [];

        foreach (self::NAMES as $name) {
            $eventType = new EventType($organization);
            $eventType->setName($name);

            $this->entityManager->persist($eventType);
            $created[] = $eventType;
        }

        return $created;
    }
}
