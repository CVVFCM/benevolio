<?php

declare(strict_types=1);

namespace App\Security;

use App\Doctrine\Filter\OrganizationFilter;
use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the volunteer of the `volunteer` firewall: a Person, by id.
 *
 * The tenant filter is suspended for the lookup. The firewall refreshes the user before
 * App\Tenant\TenantRequestListener arms the filter for this request, and under FrankenPHP
 * worker mode the EntityManager may still carry the filter armed by the previous request,
 * for another association — the volunteer would be logged out for having visited it.
 * Which association the volunteer may act for is App\Volunteer\CurrentVolunteer's
 * question, not the provider's.
 *
 * @implements UserProviderInterface<Person>
 */
final readonly class VolunteerProvider implements UserProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): Person
    {
        if (!Uuid::isValid($identifier)) {
            throw new UserNotFoundException();
        }

        $filters = $this->entityManager->getFilters();
        $suspended = $filters->isEnabled(OrganizationFilter::NAME);
        if ($suspended) {
            $filters->suspend(OrganizationFilter::NAME);
        }

        try {
            $person = $this->entityManager->find(Person::class, Uuid::fromString($identifier));
        } finally {
            if ($suspended) {
                $filters->restore(OrganizationFilter::NAME);
            }
        }

        if (!$person instanceof Person) {
            throw new UserNotFoundException();
        }

        return $person;
    }

    public function refreshUser(UserInterface $user): Person
    {
        if (!$user instanceof Person) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return Person::class === $class || is_subclass_of($class, Person::class);
    }
}
