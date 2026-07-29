<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\FiscalYear;
use App\Entity\Person;

/**
 * The draft ledger of one exercice.
 *
 * Totals are kept apart by family and must never be added together: donated hours are
 * off balance sheet (classe 8) and never receiptable, waived expenses are a real
 * donation and are. A single "total contributions" figure would be meaningless
 * accounting and would overstate what a volunteer may deduct.
 */
final readonly class Ledger
{
    /**
     * @param list<LedgerEntry> $entries
     */
    public function __construct(
        public FiscalYear $fiscalYear,
        public array $entries,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    /** Total bénévolat valorisé, in cents. Débit 864 = crédit 875. */
    public function hoursCents(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry->valuation->hoursCents;
        }

        return $total;
    }

    /** Total abandon de frais, in cents. This is the receiptable figure. */
    public function mileageCents(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry->valuation->mileageCents;
        }

        return $total;
    }

    /**
     * Entries grouped by volunteer, which is the unit a CERFA is issued for.
     *
     * @return array<string, array{person: Person, entries: list<LedgerEntry>, hoursCents: int, mileageCents: int, beyondFirstBand: bool}>
     */
    public function byVolunteer(): array
    {
        $grouped = [];

        foreach ($this->entries as $entry) {
            $person = $entry->action->getDeclaration()->getPerson();
            $key = $person->getId()->toRfc4122();

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'person' => $person,
                    'entries' => [],
                    'hoursCents' => 0,
                    'mileageCents' => 0,
                    'beyondFirstBand' => false,
                ];
            }

            $grouped[$key]['entries'][] = $entry;
            $grouped[$key]['hoursCents'] += $entry->valuation->hoursCents;
            $grouped[$key]['mileageCents'] += $entry->valuation->mileageCents;
            // Sticky: once a volunteer has crossed the first band, every later figure
            // for them is understated too.
            $grouped[$key]['beyondFirstBand'] = $grouped[$key]['beyondFirstBand'] || $entry->valuation->beyondFirstBand;
        }

        return $grouped;
    }

    /** Whether any volunteer has passed the first band, so the page can say so once. */
    public function hasBeyondFirstBand(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->valuation->beyondFirstBand) {
                return true;
            }
        }

        return false;
    }
}
