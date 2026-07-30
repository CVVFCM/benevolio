<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\State\DeclarationActionState;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function array_keys;
use function rsort;
use function sprintf;

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

    /**
     * Every validated line of a **civil year**, volunteer by volunteer.
     *
     * The unit a reçu fiscal is issued for — not the exercice, which may run September to
     * August and has its own query in App\Accounting\LedgerBuilder.
     *
     * Validated only: a line nobody has ruled on is not a donation yet, and putting it on a
     * tax receipt would assert that it is. Ordered by volunteer then date so the caller can
     * group in one pass and pick the last waived day without sorting again.
     *
     * @return list<DeclarationAction>
     */
    public function findValidatedInCivilYear(Organization $organization, int $year): array
    {
        $builder = $this->createTenantScopedQueryBuilder($organization)
            ->innerJoin('scoped_declaration.person', 'scoped_person')
            ->addSelect('scoped_declaration', 'scoped_person')
            ->andWhere('declaration_action.state = :state')
            // The line's own start date decides which year it belongs to, the same rule
            // FiscalYear::contains() applies to the exercice: a line spanning 31 December
            // belongs wholly to the year it began in.
            ->andWhere('declaration_action.date >= :from')
            ->andWhere('declaration_action.date <= :to')
            ->setParameter('state', DeclarationActionState::VALIDATED->value)
            ->setParameter('from', new DateTimeImmutable(sprintf('%d-01-01', $year)))
            ->setParameter('to', new DateTimeImmutable(sprintf('%d-12-31', $year)))
            ->addOrderBy('scoped_person.id', 'ASC')
            ->addOrderBy('declaration_action.date', 'ASC');

        /** @var list<DeclarationAction> $lines */
        $lines = $builder->getQuery()->getResult();

        return $lines;
    }

    /**
     * The civil years this association has validated contributions in, most recent first.
     *
     * Feeds the year field on the generation form: offering a year with nothing in it would
     * produce a run that reported nothing and looked broken.
     *
     * @return list<int>
     */
    public function findYearsWithValidatedActions(Organization $organization): array
    {
        // TRAP: DQL has **no YEAR() function**. The list Doctrine ships is short — ABS,
        // CONCAT, DATE_ADD, DATE_DIFF, LENGTH, SUBSTRING, TRIM and a handful more — and
        // YEAR() comes from beberlei/DoctrineExtensions, which this project does not have.
        // Writing it produces "Expected known function, got 'YEAR'" at runtime, not at
        // compile time. So the distinct dates are fetched and folded here instead; a form
        // field is not worth a DQL function registration.
        /** @var list<array{date: DateTimeImmutable}> $rows */
        $rows = $this->createTenantScopedQueryBuilder($organization)
            ->select('DISTINCT declaration_action.date')
            ->andWhere('declaration_action.state = :state')
            ->setParameter('state', DeclarationActionState::VALIDATED->value)
            ->getQuery()
            ->getResult();

        $years = [];
        foreach ($rows as $row) {
            $years[(int) $row['date']->format('Y')] = true;
        }

        $years = array_keys($years);
        // Most recent first: the year just finished is the one being issued.
        rsort($years);

        return $years;
    }
}
