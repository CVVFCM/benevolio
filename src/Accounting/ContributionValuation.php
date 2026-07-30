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
    ) {
    }
}
