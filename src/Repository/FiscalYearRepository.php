<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FiscalYear;
use App\Entity\Organization;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

use function assert;
use function sprintf;

/**
 * @extends ServiceEntityRepository<FiscalYear>
 */
final class FiscalYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FiscalYear::class);
    }

    /**
     * Exercices of one association whose range intersects the given one.
     *
     * The organization is passed explicitly rather than left to OrganizationFilter,
     * because this runs from a validator — and validation happens in CLI contexts
     * (fixtures, the console commands) where the filter is off by design. Without the
     * argument, seeding one association's exercice would collide with another's.
     *
     * `$exclude` skips the row being edited, or an update would always clash with
     * itself.
     *
     * Two ranges overlap when each begins before the other ends. Adjacent years —
     * 31/12 then 01/01 — do not.
     *
     * @return list<FiscalYear>
     */
    public function findOverlapping(
        Organization $organization,
        DateTimeImmutable $beginsOn,
        DateTimeImmutable $endsOn,
        ?Uuid $exclude = null,
    ): array {
        $builder = $this->createQueryBuilder('fiscal_year')
            ->andWhere('fiscal_year.organization = :organization')
            ->andWhere('fiscal_year.beginsOn <= :endsOn')
            ->andWhere('fiscal_year.endsOn >= :beginsOn')
            ->setParameter('organization', $organization->getId(), 'uuid')
            ->setParameter('beginsOn', $beginsOn->setTime(0, 0))
            ->setParameter('endsOn', $endsOn->setTime(0, 0))
            ->orderBy('fiscal_year.beginsOn', 'ASC');

        if (null !== $exclude) {
            $builder->andWhere('fiscal_year.id != :exclude')
                ->setParameter('exclude', $exclude, 'uuid');
        }

        /** @var list<FiscalYear> $years */
        $years = $builder->getQuery()->getResult();

        return $years;
    }

    /**
     * The exercice whose rates price a whole **civil year**: the FIRST one that intersects it.
     *
     * A receipt covers a calendar year, an exercice need not — so something has to decide which
     * rates a January-to-December total is built from, and it is one exercice for the whole year
     * rather than one per contribution. Civil 2026 is priced by 2025-2026, because that is the
     * first exercice reaching into it.
     *
     * Ordered by `beginsOn` and limited to one, which is what makes "first" mean anything. Null
     * when the association has created no exercice touching the year — an ordinary answer, and
     * the run reports it rather than inventing a rate.
     *
     * Organization passed explicitly, for the same reason as findOverlapping().
     */
    public function findFirstForCivilYear(Organization $organization, int $year): ?FiscalYear
    {
        $fiscalYear = $this->createQueryBuilder('fiscal_year')
            ->andWhere('fiscal_year.organization = :organization')
            // Intersects the civil year: begins before it ends, and ends after it begins.
            ->andWhere('fiscal_year.beginsOn <= :endOfYear')
            ->andWhere('fiscal_year.endsOn >= :startOfYear')
            ->setParameter('organization', $organization->getId(), 'uuid')
            ->setParameter('startOfYear', new DateTimeImmutable(sprintf('%d-01-01', $year)))
            ->setParameter('endOfYear', new DateTimeImmutable(sprintf('%d-12-31', $year)))
            ->orderBy('fiscal_year.beginsOn', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert(null === $fiscalYear || $fiscalYear instanceof FiscalYear);

        return $fiscalYear;
    }

    /**
     * The civil years this exercice is the first to intersect, and therefore prices.
     *
     * The inverse of findFirstForCivilYear(), and it exists for the reopen guard: an exercice
     * running September 2025 to August 2026 touches civil 2025 and 2026, but only prices the ones
     * where no earlier exercice got there first.
     *
     * @return list<int>
     */
    public function civilYearsPricedBy(FiscalYear $fiscalYear): array
    {
        $organization = $fiscalYear->getOrganization();
        $years = [];

        for (
            $year = (int) $fiscalYear->getBeginsOn()->format('Y');
            $year <= (int) $fiscalYear->getEndsOn()->format('Y');
            ++$year
        ) {
            $first = $this->findFirstForCivilYear($organization, $year);

            if (null !== $first && $first->getId()->equals($fiscalYear->getId())) {
                $years[] = $year;
            }
        }

        return $years;
    }

    /**
     * The exercice a contribution on this date belongs to, or null when the
     * association has not created one covering it.
     *
     * Null is an ordinary answer, not an error: a line outside every exercice is
     * stored and listed, it simply has no rates to be valued with.
     *
     * Organization passed explicitly, for the same reason as findOverlapping().
     */
    public function findForDate(Organization $organization, DateTimeImmutable $date): ?FiscalYear
    {
        $year = $this->createQueryBuilder('fiscal_year')
            ->andWhere('fiscal_year.organization = :organization')
            ->andWhere('fiscal_year.beginsOn <= :date')
            ->andWhere('fiscal_year.endsOn >= :date')
            ->setParameter('organization', $organization->getId(), 'uuid')
            ->setParameter('date', $date->setTime(0, 0))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert(null === $year || $year instanceof FiscalYear);

        return $year;
    }
}
