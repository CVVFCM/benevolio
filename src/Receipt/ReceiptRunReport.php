<?php

declare(strict_types=1);

namespace App\Receipt;

use RuntimeException;

use function array_filter;
use function array_values;
use function count;

/**
 * What a yearly run did, for the page and the console command to print.
 *
 * Two shapes. Either the association could not issue anything at all — no SIREN, no address
 * — and `refusalReason` says so with nothing attempted, or there is one outcome per
 * volunteer considered. "Nothing to do" is a third, quiet case: a year with no validated
 * contribution produces no outcomes and no refusal.
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
            static fn (ReceiptRunOutcome $outcome): bool => !$outcome->isIssued(),
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
