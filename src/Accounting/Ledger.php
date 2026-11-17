<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\FiscalYear;
use App\Entity\Person;
use App\ValueObject\Money;
use App\ValueObject\WorkHours;

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
        $workHours = WorkHours::zero();
        $waivedDistanceKm = 0;

        foreach ($this->entries as $entry) {
            $volunteers[$entry->action->getDeclaration()->getPerson()->getId()->toRfc4122()] = true;
            $workHours = $workHours->plus($entry->action->getHours());

            // Only travel that was actually valued. A journey as a passenger is declared
            // but waives nothing, and including it would leave the kilometres shown next
            // to the amount irreconcilable with the barème.
            if ($entry->hasMileage()) {
                $waivedDistanceKm += $entry->action->getTotalDistanceKm();
            }
        }

        return new LedgerSummary(
            fiscalYear: $this->fiscalYear,
            hours: $this->hours(),
            mileage: $this->mileage(),
            volunteerCount: count($volunteers),
            workHours: $workHours,
            waivedDistanceKm: $waivedDistanceKm,
        );
    }

    /**
     * Entries grouped by volunteer, which is what justifies an individual reçu fiscal —
     * though the receipt covers a civil year and this covers an exercice.
     *
     * @return array<string, array{person: Person, entries: list<LedgerEntry>, hours: Money, mileage: Money}>
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
                    'hours' => Money::zero(),
                    'mileage' => Money::zero(),
                ];
            }

            $grouped[$key]['entries'][] = $entry;
            $grouped[$key]['hours'] = $grouped[$key]['hours']->plus($entry->valuation->hours);
            $grouped[$key]['mileage'] = $grouped[$key]['mileage']->plus($entry->valuation->mileage);
        }

        return $grouped;
    }

    /** Total bénévolat valorisé. Débit 864 = crédit 875. */
    private function hours(): Money
    {
        $total = Money::zero();
        foreach ($this->entries as $entry) {
            $total = $total->plus($entry->valuation->hours);
        }

        return $total;
    }

    /** Total abandon de frais. This is the receiptable figure. */
    private function mileage(): Money
    {
        $total = Money::zero();
        foreach ($this->entries as $entry) {
            $total = $total->plus($entry->valuation->mileage);
        }

        return $total;
    }
}
