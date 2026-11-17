<?php

declare(strict_types=1);

namespace App\Receipt;

use RuntimeException;

use function array_filter;
use function array_find;
use function array_values;
use function count;

/**
 * What a yearly run did, for the page and the console command to print.
 *
 * Two shapes. Either the association could not issue anything at all — no SIREN, no address
 * — and `refusalReason` says so with nothing attempted, or there is one outcome per
 * volunteer considered. "Nothing to do" is a third, quiet case: a year with no validated
 * contribution produces no outcomes and no refusal.
 *
 * A run that went ahead may also have been **interrupted**: making one volunteer's receipt
 * failed, and the run stopped there. The report then still says who was receipted before
 * that, who it failed on, and who is left — which is the point of returning one at all:
 * the receipts issued before the failure are real, and without the report nobody knows
 * which they are.
 */
final readonly class ReceiptRunReport
{
    private function __construct(
        public int $year,
        private ?string $refusalReason,
        /** @var list<ReceiptRunOutcome> */
        public array $outcomes,
    ) {
    }

    /** The association itself cannot issue: nothing was generated, nothing was sent. */
    public static function refused(int $year, string $reason): self
    {
        return new self($year, $reason, []);
    }

    /**
     * @param list<ReceiptRunOutcome> $outcomes
     */
    public static function of(int $year, array $outcomes): self
    {
        return new self($year, null, $outcomes);
    }

    public function isRefused(): bool
    {
        return null !== $this->refusalReason;
    }

    public function refusalReason(): string
    {
        return $this->refusalReason ?? throw new RuntimeException('No refusal reason on a run that went ahead.');
    }

    public function hasNothingToDo(): bool
    {
        return !$this->isRefused() && [] === $this->outcomes;
    }

    /** @return list<ReceiptRunOutcome> */
    public function issued(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (ReceiptRunOutcome $outcome): bool => $outcome->isIssued(),
        ));
    }

    /** @return list<ReceiptRunOutcome> */
    public function skipped(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (ReceiptRunOutcome $outcome): bool => $outcome->isSkipped(),
        ));
    }

    /**
     * True when making a receipt failed and the run stopped before the end.
     */
    public function isInterrupted(): bool
    {
        return null !== $this->failure();
    }

    /**
     * The volunteer the run stopped on, if it did. At most one: the run does not go on
     * past a failure.
     */
    public function failure(): ?ReceiptRunOutcome
    {
        return array_find(
            $this->outcomes,
            static fn (ReceiptRunOutcome $outcome): bool => $outcome->isFailed(),
        );
    }

    /**
     * Volunteers owed a receipt that the run never got to. Running the year again issues
     * theirs — and, being a new run, re-issues the ones already made under new numbers.
     *
     * @return list<ReceiptRunOutcome>
     */
    public function notAttempted(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (ReceiptRunOutcome $outcome): bool => $outcome->isNotAttempted(),
        ));
    }

    public function issuedCount(): int
    {
        return count($this->issued());
    }

    public function skippedCount(): int
    {
        return count($this->skipped());
    }

    /**
     * The receipted total, in cents.
     *
     * The sum of what was issued, and nothing else: it is a figure to check the run
     * against, not an accounting total — the exercice's écriture is at
     * App\Accounting\LedgerSummary and covers a different period.
     */
    public function totalCents(): int
    {
        $total = 0;

        foreach ($this->issued() as $outcome) {
            $total += $outcome->receipt()->getAmountCents();
        }

        return $total;
    }

    /** How many lines were left out of the amounts for want of an exercice. */
    public function unvaluedLineCount(): int
    {
        $total = 0;

        foreach ($this->outcomes as $outcome) {
            $total += $outcome->unvaluedLineCount;
        }

        return $total;
    }
}
