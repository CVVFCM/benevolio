<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Filtered in PHP rather than in SQL: `roles` is a `json` column, which DQL
     * cannot look into, and an association has a handful of accounts at most.
     */
    public function findOwnerOf(Organization $organization): ?User
    {
        return array_find(
            $this->findBy(['organization' => $organization]),
            static fn (User $user): bool => $user->isOwner(),
        );
    }

    public function organizationHasOwner(Organization $organization): bool
    {
        return null !== $this->findOwnerOf($organization);
    }

    /**
     * Narrows a query on `$alias` to one organization's accounts.
     *
     * User is not TenantAware — the tenant filter must never touch the user
     * provider — so the /admin listing scopes itself with this.
     */
    public function scopeToOrganization(QueryBuilder $queryBuilder, string $alias, Organization $organization): QueryBuilder
    {
        return $queryBuilder
            ->andWhere($alias.'.organization = :scoped_organization')
            ->setParameter('scoped_organization', $organization->getId(), 'uuid');
    }
}
