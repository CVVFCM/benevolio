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
    ) {
    }

    public static function issued(Receipt $receipt): self
    {
        return new self($receipt->getPerson(), $receipt, null);
    }

    public static function skipped(Person $person, string $reason): self
    {
        return new self($person, null, $reason);
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
