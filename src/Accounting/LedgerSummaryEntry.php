<?php

declare(strict_types=1);

namespace App\Accounting;

use DateTimeImmutable;

/**
 * One line of the centralising écriture — an account, a side, and an amount.
 *
 * Carries its own date even though every line of a summary shares one: PCG art. 1032-1
 * requires each record to carry its date, and a line handed to a template without one
 * would let the template invent it.
 *
 * A line is one-sided. `debitCents` and `creditCents` are never both non-zero, because
 * an écriture is read as two facing columns and a row filling both would be unreadable.
 */
final readonly class LedgerSummaryEntry
{
    public function __construct(
        public DateTimeImmutable $date,
        public PcgAccount $account,
        public string $label,
        public int $debitCents = 0,
        public int $creditCents = 0,
    ) {
    }
}
