<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\DeclarationAction;

/**
 * One validated contribution, with what it is worth.
 *
 * A line produces up to three accounting entries, which is why the template walks this
 * rather than a flat list of rows:
 *
 *   - donated hours:  débit 864 / crédit 875               (classe 8, never receiptable)
 *   - waived travel:  débit 6251 / crédit 4681,
 *                     then débit 4681 / crédit 75412       (a real donation, receiptable)
 */
final readonly class LedgerEntry
{
    public function __construct(
        public DeclarationAction $action,
        public ContributionValuation $valuation,
    ) {
    }

    /** Hours are always declared, but a line can legitimately have none. */
    public function hasHours(): bool
    {
        return $this->valuation->hoursCents > 0;
    }

    /** Travel only counts when the volunteer used their own vehicle. */
    public function hasMileage(): bool
    {
        return $this->valuation->mileageCents > 0;
    }
}
