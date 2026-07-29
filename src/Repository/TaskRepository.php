<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
final class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * The choices offered by the public declaration form.
     *
     * No organization argument: Task is TenantAware, so OrganizationFilter
     * has already restricted this to the current tenant. Calling it from a CLI
     * context — where the filter is off by design — would return every
     * association's types.
     */
    public function activeQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('task')
            ->andWhere('task.active = true')
            ->orderBy('task.name', 'ASC');
    }

    /**
     * @return list<Task>
     */
    public function findActive(): array
    {
        /** @var list<Task> $types */
        $types = $this->activeQueryBuilder()->getQuery()->getResult();

        return $types;
    }
}
