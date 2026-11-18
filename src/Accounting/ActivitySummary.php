<?php

declare(strict_types=1);

namespace App\Accounting;

use App\ValueObject\Money;
use App\ValueObject\WorkHours;

/**
 * What the validated lines given for one event or action add up to (#47).
 *
 * Two amounts, and deliberately no total: valued hours are a contribution volontaire en
 * nature (864/875, never receiptable), waived mileage is a donation (75412). See
 * ContributionValuation — summing them has no accounting meaning.
 *
 * The kilometres are the VALUED ones, driven in the volunteer's own vehicle, so they
 * reconcile with the amount beside them — as on LedgerSummary.
 *
 * A line no exercice covers has no rate: its hours, kilometres and volunteer are
 * counted, and it adds nothing to either amount.
 */
final readonly class ActivitySummary
{
    public function __construct(
        public WorkHours $hours,
        public int $valuedKm,
        /** Bénévolat valorisé. */
        public Money $hoursAmount,
        /** Abandon de frais. */
        public Money $mileageAmount,
        /** Distinct volunteers. */
        public int $volunteers,
    ) {
    }

    public static function empty(): self
    {
        return new self(WorkHours::zero(), 0, new Money(0), new Money(0), 0);
    }
}
