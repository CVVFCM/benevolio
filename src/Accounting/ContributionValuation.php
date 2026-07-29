<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\FiscalYear;

/**
 * What one contributed line is worth, and under which exercice.
 *
 * Both amounts are in CENTS. The two are kept apart and must never be summed: donated
 * hours are a contribution volontaire en nature (classe 8, off balance sheet, never
 * receiptable), while waived travel is a real donation (75412, receiptable). Adding
 * them would produce a figure with no accounting meaning and a receipt that overstates
 * what the volunteer may deduct.
 */
final readonly class ContributionValuation
{
    public function __construct(
        /** Donated hours × the hourly rate. Débit 864 / crédit 875. */
        public int $hoursCents,
        /** Kilometres × the barème. Débit 6251 → 4681 → crédit 75412. Zero without travel. */
        public int $mileageCents,
        /** The exercice whose rates were used. */
        public FiscalYear $fiscalYear,
        /**
         * True when this volunteer's kilometres for the exercice have passed the first
         * band of the barème, so `mileageCents` is **understated**.
         *
         * The scale beyond 5 000 km uses a different formula with an additive constant,
         * which this application does not model — see FiscalYear::FIRST_BAND_LIMIT_KM.
         * Surfaced rather than swallowed: a treasurer must not put a figure on a CERFA
         * without knowing it is short.
         */
        public bool $beyondFirstBand,
    ) {
    }
}
