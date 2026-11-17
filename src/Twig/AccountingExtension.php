<?php

declare(strict_types=1);

namespace App\Twig;

use App\Accounting\PcgAccount;
use App\ValueObject\Money;
use App\ValueObject\WorkHours;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Formatting for the accounting pages.
 *
 * `euros` exists so no template ever writes `cents / 100`. Dividing in Twig would put a
 * float in the middle of a money path — the one thing storing cents is meant to prevent
 * — and `format_currency` would need exactly that. Here the split is integral and the
 * decimal separator is the comma a French reader expects.
 *
 * `hours` is the same idea for durations, which are stored in hundredths of an hour.
 *
 * `pcg_account` keeps account numbers out of the markup as bare strings: a template
 * asking for '870' instead of '875' would be a wrong ledger with nothing to catch it,
 * whereas an unknown case throws.
 */
final class AccountingExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('euros', $this->euros(...)),
            new TwigFilter('hours', $this->hours(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pcg_account', $this->pcgAccount(...)),
        ];
    }

    /**
     * An amount, the French way: "1 234,56 €". Takes the value object, or a bare count of
     * cents where a template reads a column directly.
     */
    public function euros(Money|int $amount): string
    {
        return ($amount instanceof Money ? $amount : new Money($amount))->format();
    }

    /**
     * A duration: "12,75 h". Takes the value object, or a bare count of hundredths.
     */
    public function hours(WorkHours|int $hours): string
    {
        return ($hours instanceof WorkHours ? $hours : new WorkHours($hours))->format();
    }

    /**
     * The account by its code, so a template cannot invent one.
     */
    public function pcgAccount(string $code): PcgAccount
    {
        return PcgAccount::from($code);
    }
}
