<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\DeclarationAction;
use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Repository\DeclarationActionRepository;
use App\Repository\FiscalYearRepository;
use App\ValueObject\Money;
use App\ValueObject\WorkHours;

use function count;

/**
 * Adds up, per event or action, the validated lines given for it (#47).
 *
 * Validated only, like the ledger: a line nobody has ruled on is not booked, and a
 * figure including it would not match the accounts.
 *
 * All time, not one exercice — an activity has none of its own. Each line is priced by
 * the exercice covering ITS date, as everywhere; the association's exercices are loaded
 * once and matched in memory, so there is no query per line.
 */
final readonly class ActivitySummaryBuilder
{
    public function __construct(
        private DeclarationActionRepository $actions,
        private FiscalYearRepository $fiscalYears,
        private ContributionValuator $valuator,
    ) {
    }

    /**
     * Keyed by the activity's id. An activity nobody has a validated line for is absent:
     * ask with `?? ActivitySummary::empty()`.
     *
     * @return array<string, ActivitySummary>
     */
    public function forOrganization(Organization $organization): array
    {
        $fiscalYears = $this->fiscalYears->findBy(['organization' => $organization]);

        /** @var array<string, array{hours: WorkHours, km: int, hoursAmount: Money, mileageAmount: Money, people: array<string, true>}> $totals */
        $totals = [];

        foreach ($this->actions->findValidatedWithActivity($organization) as $action) {
            $activity = $action->getActivity();
            if (null === $activity) {
                continue;
            }

            $key = $activity->getId()->toRfc4122();
            $total = $totals[$key] ?? [
                'hours' => WorkHours::zero(),
                'km' => 0,
                'hoursAmount' => new Money(0),
                'mileageAmount' => new Money(0),
                'people' => [],
            ];

            $total['hours'] = $total['hours']->plus($action->getHours());
            $total['people'][$action->getDeclaration()->getPerson()->getId()->toRfc4122()] = true;

            if ($action->usesOwnVehicle()) {
                $total['km'] += $action->getTotalDistanceKm();
            }

            $fiscalYear = self::covering($fiscalYears, $action);
            if (null !== $fiscalYear) {
                $valuation = $this->valuator->valueWithin($action, $fiscalYear);
                $total['hoursAmount'] = $total['hoursAmount']->plus($valuation->hours);
                $total['mileageAmount'] = $total['mileageAmount']->plus($valuation->mileage);
            }

            $totals[$key] = $total;
        }

        $summaries = [];
        foreach ($totals as $key => $total) {
            $summaries[$key] = new ActivitySummary(
                $total['hours'],
                $total['km'],
                $total['hoursAmount'],
                $total['mileageAmount'],
                count($total['people']),
            );
        }

        return $summaries;
    }

    /**
     * The exercice a line belongs to: the one containing its start date. Exercices of
     * one association never overlap, so the first match is the only one.
     *
     * @param list<FiscalYear> $fiscalYears
     */
    private static function covering(array $fiscalYears, DeclarationAction $action): ?FiscalYear
    {
        foreach ($fiscalYears as $fiscalYear) {
            if ($fiscalYear->contains($action->getDate())) {
                return $fiscalYear;
            }
        }

        return null;
    }
}
