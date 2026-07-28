<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeclarationAction;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeclarationAction>
 */
final class DeclarationActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeclarationAction::class);
    }

    /**
     * DeclarationAction is NOT TenantAware, so OrganizationFilter does not scope
     * it. Every query on this entity has to say which tenant it means — this
     * method is the single place that knows how, and callers should prefer it over
     * building their own query builder.
     */
    public function createTenantScopedQueryBuilder(Organization $organization, string $alias = 'declaration_action'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->innerJoin($alias.'.declaration', 'scoped_declaration')
            ->andWhere('scoped_declaration.organization = :tenant')
            ->setParameter('tenant', $organization->getId(), 'uuid');
    }
}
