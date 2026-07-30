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
 * Grouped by volunteer on the detail page, because that is the unit a reçu fiscal is issued
 * for — though the receipt itself is a civil year and this is an exercice, so the two never
 * share a query. See App\Receipt\YearlyReceiptRun.
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
        $entries = [];

        foreach ($this->validatedActionsIn($fiscalYear) as $action) {
            $entries[] = new LedgerEntry(
                action: $action,
                valuation: $this->valuator->valueWithin($action, $fiscalYear),
            );
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
            // Date order because it reads like a journal, not because anything depends on
            // it: nothing accumulates across lines any more.
            ->setParameter('state', DeclarationActionState::VALIDATED->value)
            ->setParameter('beginsOn', $fiscalYear->getBeginsOn())
            ->setParameter('endsOn', $fiscalYear->getEndsOn())
            ->addOrderBy('declaration_action.date', 'ASC');

        /** @var list<DeclarationAction> $lines */
        $lines = $builder->getQuery()->getResult();

        return $lines;
    }
}
