<?php

declare(strict_types=1);

namespace App\Accounting;

use App\ValueObject\Money;

/**
 * One account's débit and crédit over an exercice.
 *
 * A class and not an `array<string, …>` keyed by account number: PCG codes are numeric
 * strings, and PHP silently turns '864' into the integer key 864 — a keyed array of them
 * cannot even be typed honestly, let alone read back by code that thinks in strings.
 */
final readonly class AccountBalance
{
    public function __construct(
        public PcgAccount $account,
        public Money $debit,
        public Money $credit,
    ) {
    }
}
