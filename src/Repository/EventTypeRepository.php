<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventType>
 */
final class EventTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventType::class);
    }

    /**
     * The choices offered by the public declaration form.
     *
     * No organization argument: EventType is TenantAware, so OrganizationFilter
     * has already restricted this to the current tenant. Calling it from a CLI
     * context — where the filter is off by design — would return every
     * association's types.
     */
    public function activeQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('event_type')
            ->andWhere('event_type.active = true')
            ->orderBy('event_type.name', 'ASC');
    }

    /**
     * @return list<EventType>
     */
    public function findActive(): array
    {
        /** @var list<EventType> $types */
        $types = $this->activeQueryBuilder()->getQuery()->getResult();

        return $types;
    }
}
