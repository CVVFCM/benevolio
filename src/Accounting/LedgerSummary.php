<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Entity\FiscalYear;

use function array_filter;
use function array_values;

/**
 * The exercice as it goes into the accounts: one écriture, six lines.
 *
 * This is what an accountant actually books — a single opération diverse passed at the
 * close of the exercice — and not the per-volunteer detail App\Accounting\Ledger holds.
 * The detail justifies an individual receipt; this is the entry that reaches the journal.
 *
 * All six lines carry the exercice's **closing date**, not the date of any contribution.
 * The movements happened throughout the year; the écriture that records them is passed at
 * closing, and dating it on a contribution's date would assert a booking that never
 * occurred that day.
 *
 * The two families are still never summed — the invariant App\Accounting\Ledger explains.
 * A summary that produced a "total des contributions" would be exactly the meaningless
 * figure both classes exist to prevent.
 */
final readonly class LedgerSummary
{
    public function __construct(
        public FiscalYear $fiscalYear,
        /** Total bénévolat valorisé, in cents. Débit 864 = crédit 875. */
        public int $hoursCents,
        /** Total abandon de frais, in cents. The receiptable family. */
        public int $mileageCents,
        /** How many volunteers stand behind those figures. */
        public int $volunteerCount,
        /** Donated hours, in hundredths — integers all the way, like every amount here. */
        public int $workHoursInHundredths,
        /**
         * Kilometres that were actually **valued** — travel in the volunteer's own
         * vehicle. Not every declared kilometre: a passenger's journey is declared but
         * waives nothing, and counting it here would leave a reader unable to reconcile
         * these kilometres against the barème and the amount beside them.
         */
        public int $waivedDistanceKm,
        /** True when any volunteer passed the first band, so the page can say so once. */
        public bool $beyondFirstBand,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->hoursCents && 0 === $this->mileageCents;
    }

    /**
     * The lines of the écriture, in the order they are booked.
     *
     * A family whose total is zero contributes nothing. An association with donated hours
     * and no waived expenses must not be shown three rows of `0,00 €` against 6251, 4681
     * and 75412 — that would read as an écriture it is expected to pass.
     *
     * @return list<LedgerSummaryEntry>
     */
    public function entries(): array
    {
        $date = $this->fiscalYear->getEndsOn();
        $entries = [];

        // Bénévolat valorisé. Débit 864 / crédit 875 under règlement ANC 2018-06, which
        // swapped 870 and 875 from the old 99-01. Off balance sheet, never receiptable.
        if (0 !== $this->hoursCents) {
            $entries[] = new LedgerSummaryEntry(
                $date,
                PcgAccount::PERSONNEL_BENEVOLE,
                'Bénévolat valorisé de l\'exercice',
                debitCents: $this->hoursCents,
            );
            $entries[] = new LedgerSummaryEntry(
                $date,
                PcgAccount::BENEVOLAT,
                'Contrepartie du bénévolat valorisé',
                creditCents: $this->hoursCents,
            );
        }

        // Abandon de frais, in two écritures. Art. 141-4 requires the waiver to
        // extinguish the debt owed to the volunteer, so 4681 stands between the charge
        // and the resource: the donation is not credited out of nowhere.
        if (0 !== $this->mileageCents) {
            $entries[] = new LedgerSummaryEntry(
                $date,
                PcgAccount::VOYAGES_ET_DEPLACEMENTS,
                'Frais de déplacement des bénévoles',
                debitCents: $this->mileageCents,
            );
            $entries[] = new LedgerSummaryEntry(
                $date,
                PcgAccount::FRAIS_DES_BENEVOLES,
                'Frais à rembourser aux bénévoles',
                creditCents: $this->mileageCents,
            );
            $entries[] = new LedgerSummaryEntry(
                $date,
                PcgAccount::FRAIS_DES_BENEVOLES,
                'Renonciation au remboursement',
                debitCents: $this->mileageCents,
            );
            $entries[] = new LedgerSummaryEntry(
                $date,
                PcgAccount::ABANDONS_DE_FRAIS,
                'Abandon de frais par les bénévoles',
                creditCents: $this->mileageCents,
            );
        }

        return $entries;
    }

    /**
     * The classe 8 lines: bénévolat valorisé, off balance sheet.
     *
     * Split from the rest because they are **two different écritures**, not one. Presented
     * in a single table they would invite a combined total, which is precisely the figure
     * that must never exist — see the class docblock.
     *
     * @return list<LedgerSummaryEntry>
     */
    public function offBalanceSheetEntries(): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (LedgerSummaryEntry $entry): bool => $entry->account->isOffBalanceSheet(),
        ));
    }

    /**
     * The abandon de frais lines: a real flow, and the receiptable family.
     *
     * @return list<LedgerSummaryEntry>
     */
    public function realFlowEntries(): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (LedgerSummaryEntry $entry): bool => !$entry->account->isOffBalanceSheet(),
        ));
    }

    /**
     * Débit and crédit totalled per account, in the order the accounts are booked.
     *
     * Folded out of entries() rather than written out again, so the balance cannot drift
     * from the écriture it is supposed to summarise — 4681 appears on both sides and is
     * the one an independent listing would get wrong.
     *
     * @return list<AccountBalance>
     */
    public function balances(): array
    {
        /** @var array<string, PcgAccount> $accounts in the order they were first booked */
        $accounts = [];
        /** @var array<string, int> $debits */
        $debits = [];
        /** @var array<string, int> $credits */
        $credits = [];

        foreach ($this->entries() as $entry) {
            // Prefixed, because a PCG code is a numeric string and PHP would turn the key
            // '864' into the integer 864 — see App\Accounting\AccountBalance.
            $key = 'pcg-'.$entry->account->value;

            $accounts[$key] = $entry->account;
            $debits[$key] = ($debits[$key] ?? 0) + $entry->debitCents;
            $credits[$key] = ($credits[$key] ?? 0) + $entry->creditCents;
        }

        $balances = [];
        foreach ($accounts as $key => $account) {
            $balances[] = new AccountBalance($account, $debits[$key] ?? 0, $credits[$key] ?? 0);
        }

        return $balances;
    }
}
