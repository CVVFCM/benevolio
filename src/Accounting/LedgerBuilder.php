<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\DeclarationAction;
use App\Entity\FiscalYear;
use App\Repository\DeclarationActionRepository;
use App\State\DeclarationActionState;

/**
 * Turns the validated contributions of one exercice into accounting entries.
 *
 * **Validated only.** A line a treasurer has not ruled on is not bookable, and putting
 * it in a PCG table would say it is.
 *
 * Grouped by volunteer, because that is the unit a CERFA is issued for and the unit the
 * barème's distance bands are keyed to — and it is what lets the first-band check mean
 * anything: 5 000 km is per volunteer per year, not per line.
 */
final readonly class LedgerBuilder
{
    public function __construct(
        private DeclarationActionRepository $actions,
        private ContributionValuator $valuator,
    ) {
    }

    public function build(FiscalYear $fiscalYear): Ledger
    {
        $lines = $this->validatedActionsIn($fiscalYear);

        // Kilometres accumulate per volunteer across the exercice, so the lines have to
        // be walked in date order for the band check to be truthful about which line
        // crossed the limit.
        $entries = [];
        $kmSoFar = [];

        foreach ($lines as $action) {
            $personId = $action->getDeclaration()->getPerson()->getId()->toRfc4122();
            $prior = $kmSoFar[$personId] ?? 0;

            $entries[] = new LedgerEntry(
                action: $action,
                valuation: $this->valuator->valueWithin($action, $fiscalYear, $prior),
            );

            $kmSoFar[$personId] = $prior + $action->getTotalDistanceKm();
        }

        return new Ledger($fiscalYear, $entries);
    }

    /**
     * @return list<DeclarationAction>
     */
    private function validatedActionsIn(FiscalYear $fiscalYear): array
    {
        // createTenantScopedQueryBuilder() is the only correct way to query this
        // entity: DeclarationAction is deliberately NOT TenantAware, so
        // OrganizationFilter does not scope it and the join has to be explicit.
        $builder = $this->actions
            ->createTenantScopedQueryBuilder($fiscalYear->getOrganization())
            ->andWhere('declaration_action.state = :state')
            // The action's own start date decides which exercice it belongs to — see
            // FiscalYear::contains(). An action spanning a year boundary belongs wholly
            // to the year it began in.
            ->andWhere('declaration_action.date >= :beginsOn')
            ->andWhere('declaration_action.date <= :endsOn')
            ->setParameter('state', DeclarationActionState::VALIDATED->value)
            ->setParameter('beginsOn', $fiscalYear->getBeginsOn())
            ->setParameter('endsOn', $fiscalYear->getEndsOn())
            ->addOrderBy('declaration_action.date', 'ASC');

        /** @var list<DeclarationAction> $lines */
        $lines = $builder->getQuery()->getResult();

        return $lines;
    }
}
