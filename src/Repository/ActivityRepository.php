<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Enum\ActivityType;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
final class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * The choices offered by the public declaration form: active, and already begun —
     * a line must be over to be declared, so an event still to come has nothing to
     * declare against.
     *
     * Events first, then actions; within each, the dated ones most recent first, then
     * the undated ones by name. The form groups by type, and a group's place is that of
     * its first choice.
     *
     * No organization argument, as for TaskRepository::activeQueryBuilder(): the tenant
     * filter scopes it, and in CLI it would return every association's.
     */
    public function offeredQueryBuilder(DateTimeImmutable $today): QueryBuilder
    {
        return $this->createQueryBuilder('activity')
            ->addSelect('CASE WHEN activity.type = :event THEN 0 ELSE 1 END AS HIDDEN typeRank')
            ->addSelect('CASE WHEN activity.beginsOn IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->andWhere('activity.active = true')
            ->andWhere('activity.beginsOn IS NULL OR activity.beginsOn <= :today')
            ->setParameter('event', ActivityType::EVENT)
            ->setParameter('today', $today->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('typeRank', 'ASC')
            ->addOrderBy('undated', 'ASC')
            ->addOrderBy('activity.beginsOn', 'DESC')
            ->addOrderBy('activity.name', 'ASC');
    }
}
