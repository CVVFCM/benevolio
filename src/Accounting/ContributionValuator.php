<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\DeclarationAction;
use App\Entity\FiscalYear;
use App\Repository\FiscalYearRepository;

use function assert;
use function intdiv;

/**
 * Puts a euro figure on a contributed line.
 *
 * ALL ARITHMETIC IS INTEGRAL. `ext-bcmath` is not installed, and a float has no place
 * on a path that ends on a tax receipt — 0,529 is not representable in binary, and
 * neither is 7,25 × 12. Every operand is already an integer by the time it arrives:
 * hours as hundredths (`getWorkHoursInHundredths()`), the mileage rate as millièmes
 * d'euro. Only the final division rounds, and it rounds half away from zero, which is
 * the *arrondi au centime le plus proche* an accountant expects.
 *
 * Returns null when no exercice covers the line's date. That is an ordinary answer,
 * not a failure: without a barème for the period there is no figure to state, and
 * inventing one — or defaulting to zero — would be worse than saying so.
 */
final readonly class ContributionValuator
{
    public function __construct(
        private FiscalYearRepository $fiscalYears,
    ) {
    }

    public function value(DeclarationAction $action): ?ContributionValuation
    {
        $fiscalYear = $this->fiscalYears->findForDate(
            $action->getOrganization(),
            $action->getDate(),
        );

        if (null === $fiscalYear) {
            return null;
        }

        return $this->valueWithin($action, $fiscalYear);
    }

    /**
     * The same calculation against an exercice the caller already has.
     *
     * The ledger page loads one exercice and every line inside it, so it would
     * otherwise re-query the fiscal year once per line to be told what it already
     * knows.
     */
    public function valueWithin(DeclarationAction $action, FiscalYear $fiscalYear): ContributionValuation
    {
        return new ContributionValuation(
            hoursCents: $this->hoursCents($action, $fiscalYear),
            mileageCents: $this->mileageCents($action, $fiscalYear),
            fiscalYear: $fiscalYear,
        );
    }

    /**
     * hundredths of an hour × cents per hour ÷ 100 → cents.
     */
    private function hoursCents(DeclarationAction $action, FiscalYear $fiscalYear): int
    {
        $rateCents = $fiscalYear->hourlyRateCentsFor($action->getTask());

        return self::divideRoundingHalfUp(
            $action->getWorkHoursInHundredths() * $rateCents,
            100,
        );
    }

    /**
     * kilometres × millièmes d'euro per km ÷ 10 → cents.
     *
     * Ten, not a thousand: a millième is a tenth of a cent, and the result is wanted in
     * cents. 137 km at 529 gives 72 473 tenths of a cent, so 7 247 cents — 72,47 €.
     */
    private function mileageCents(DeclarationAction $action, FiscalYear $fiscalYear): int
    {
        if (!$action->usesOwnVehicle()) {
            return 0;
        }

        $fiscalPower = $action->getFiscalPower();
        // Guaranteed non-null when usesOwnVehicle() is true by the Assert\Expression on
        // DeclarationAction, which is why this is an assertion and not a branch.
        assert(null !== $fiscalPower);

        return self::divideRoundingHalfUp(
            $action->getTotalDistanceKm() * $fiscalYear->milliEurosPerKmFor($fiscalPower),
            10,
        );
    }

    /**
     * Integer division rounding half away from zero.
     *
     * PHP's intdiv() truncates, which would quietly shave a centime off roughly half of
     * all valuations — and always in the association's favour, which is the direction an
     * auditor notices. Adding half the divisor before truncating rounds instead.
     */
    private static function divideRoundingHalfUp(int $numerator, int $divisor): int
    {
        assert($divisor > 0);

        return $numerator >= 0
            ? intdiv($numerator + intdiv($divisor, 2), $divisor)
            : -intdiv(-$numerator + intdiv($divisor, 2), $divisor);
    }
}
