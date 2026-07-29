<?php

declare(strict_types=1);

namespace App\Twig;

use App\Accounting\PcgAccount;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function abs;
use function intdiv;
use function number_format;
use function sprintf;

/**
 * Formatting for the accounting pages.
 *
 * `euros` exists so no template ever writes `cents / 100`. Dividing in Twig would put a
 * float in the middle of a money path — the one thing storing cents is meant to prevent
 * — and `format_currency` would need exactly that. Here the split is integral and the
 * decimal separator is the comma a French reader expects.
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
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pcg_account', $this->pcgAccount(...)),
        ];
    }

    /**
     * Cents to a French euro string: "7247" → "72,47 €", with a non-breaking space so
     * the amount never wraps away from its sign.
     */
    public function euros(int $cents): string
    {
        $units = intdiv($cents, 100);
        $remainder = abs($cents % 100);

        // Grouped in threes, because an exercice total can run to thousands of euros.
        return sprintf(
            "%s,%02d\u{a0}€",
            number_format($units, 0, ',', "\u{202f}"),
            $remainder,
        );
    }

    /**
     * The account by its code, so a template cannot invent one.
     */
    public function pcgAccount(string $code): PcgAccount
    {
        return PcgAccount::from($code);
    }
}
