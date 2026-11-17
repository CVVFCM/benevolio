<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Person;
use App\Entity\Receipt;
use RuntimeException;

/**
 * What happened to one volunteer in a yearly run.
 *
 * Four endings. A receipt was issued; or it was skipped, with a reason in French —
 * usually "this volunteer waived nothing"; or making it **failed**, which stops the run;
 * or it was **not attempted**, because the run had already stopped. All four are printed
 * on the report: a run that quietly did nothing for half the association would look
 * identical to one that worked.
 */
final readonly class ReceiptRunOutcome
{
    private function __construct(
        public Person $person,
        private ReceiptRunOutcomeKind $kind,
        private ?Receipt $receipt,
        private ?string $reason,
        /**
         * Lines of this volunteer's year that no exercice covers, and which are therefore
         * **not in the amount**. Reported rather than swallowed: without a barème for the
         * period there is no figure to state, and a treasurer needs to know their receipt
         * is short before the volunteer files a tax return with it.
         */
        public int $unvaluedLineCount,
    ) {
    }

    public static function issued(Receipt $receipt, int $unvaluedLineCount = 0): self
    {
        return new self($receipt->getPerson(), ReceiptRunOutcomeKind::Issued, $receipt, null, $unvaluedLineCount);
    }

    public static function skipped(Person $person, string $reason, int $unvaluedLineCount = 0): self
    {
        return new self($person, ReceiptRunOutcomeKind::Skipped, null, $reason, $unvaluedLineCount);
    }

    /**
     * The document could not be made, stored or recorded. Nothing was issued for this
     * volunteer: the number, the object and the row commit together or not at all.
     */
    public static function failed(Person $person, string $reason, int $unvaluedLineCount = 0): self
    {
        return new self($person, ReceiptRunOutcomeKind::Failed, null, $reason, $unvaluedLineCount);
    }

    /** Owed a receipt, but the run stopped on someone else before reaching them. */
    public static function notAttempted(Person $person, int $unvaluedLineCount = 0): self
    {
        return new self($person, ReceiptRunOutcomeKind::NotAttempted, null, null, $unvaluedLineCount);
    }

    public function isIssued(): bool
    {
        return ReceiptRunOutcomeKind::Issued === $this->kind;
    }

    public function isSkipped(): bool
    {
        return ReceiptRunOutcomeKind::Skipped === $this->kind;
    }

    public function isFailed(): bool
    {
        return ReceiptRunOutcomeKind::Failed === $this->kind;
    }

    public function isNotAttempted(): bool
    {
        return ReceiptRunOutcomeKind::NotAttempted === $this->kind;
    }

    public function receipt(): Receipt
    {
        return $this->receipt ?? throw new RuntimeException('No receipt on this outcome; check isIssued() first.');
    }

    public function skipReason(): string
    {
        return $this->isSkipped() && null !== $this->reason
            ? $this->reason
            : throw new RuntimeException('No skip reason on this outcome; check isSkipped() first.');
    }

    public function failureReason(): string
    {
        return $this->isFailed() && null !== $this->reason
            ? $this->reason
            : throw new RuntimeException('No failure reason on this outcome; check isFailed() first.');
    }
}
