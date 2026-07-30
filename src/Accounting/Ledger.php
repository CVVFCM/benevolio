<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\FiscalYear;
use App\Entity\Person;

use function count;

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
     * The exercice as one centralising écriture — what actually reaches the journal.
     *
     * Walked once, here, rather than left to the template: the volunteer count is a
     * distinct count and the kilometres are only the valued ones, neither of which Twig
     * should be deciding.
     */
    public function summary(): LedgerSummary
    {
        $volunteers = [];
        $workHoursInHundredths = 0;
        $waivedDistanceKm = 0;

        foreach ($this->entries as $entry) {
            $volunteers[$entry->action->getDeclaration()->getPerson()->getId()->toRfc4122()] = true;
            $workHoursInHundredths += $entry->action->getWorkHoursInHundredths();

            // Only travel that was actually valued. A journey as a passenger is declared
            // but waives nothing, and including it would leave the kilometres shown next
            // to the amount irreconcilable with the barème.
            if ($entry->hasMileage()) {
                $waivedDistanceKm += $entry->action->getTotalDistanceKm();
            }
        }

        return new LedgerSummary(
            fiscalYear: $this->fiscalYear,
            hoursCents: $this->hoursCents(),
            mileageCents: $this->mileageCents(),
            volunteerCount: count($volunteers),
            workHoursInHundredths: $workHoursInHundredths,
            waivedDistanceKm: $waivedDistanceKm,
        );
    }

    /**
     * Entries grouped by volunteer, which is what justifies an individual reçu fiscal —
     * though the receipt covers a civil year and this covers an exercice.
     *
     * @return array<string, array{person: Person, entries: list<LedgerEntry>, hoursCents: int, mileageCents: int}>
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
                ];
            }

            $grouped[$key]['entries'][] = $entry;
            $grouped[$key]['hoursCents'] += $entry->valuation->hoursCents;
            $grouped[$key]['mileageCents'] += $entry->valuation->mileageCents;
        }

        return $grouped;
    }
}
