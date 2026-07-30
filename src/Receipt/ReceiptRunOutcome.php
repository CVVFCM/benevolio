<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Person;
use App\Entity\Receipt;
use RuntimeException;

/**
 * What happened to one volunteer in a yearly run.
 *
 * Either a receipt was issued, or it was not and there is a reason in French. Both are
 * ordinary results — most skips are simply "this volunteer waived nothing" — and both are
 * printed on the report, because a run that quietly did nothing for half the association
 * would look identical to one that worked.
 */
final readonly class ReceiptRunOutcome
{
    private function __construct(
        public Person $person,
        private ?Receipt $receipt,
        private ?string $skipReason,
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
        return new self($receipt->getPerson(), $receipt, null, $unvaluedLineCount);
    }

    public static function skipped(Person $person, string $reason, int $unvaluedLineCount = 0): self
    {
        return new self($person, null, $reason, $unvaluedLineCount);
    }

    public function isIssued(): bool
    {
        return null !== $this->receipt;
    }

    public function receipt(): Receipt
    {
        return $this->receipt ?? throw new RuntimeException('No receipt on a skipped outcome; check isIssued() first.');
    }

    public function skipReason(): string
    {
        return $this->skipReason ?? throw new RuntimeException('No reason on an issued outcome.');
    }
}
