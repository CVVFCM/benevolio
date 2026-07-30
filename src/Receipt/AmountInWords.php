<?php

declare(strict_types=1);

namespace App\Receipt;

use NumberFormatter;
use RuntimeException;

use function intdiv;
use function sprintf;
use function ucfirst;

/**
 * The « somme en toutes lettres » that sits beside the figure on the CERFA.
 *
 * THE ONLY PLACE in this codebase that touches NumberFormatter, and deliberately so.
 * PHPStan resolves the class to `Symfony\Polyfill\Intl\Icu\NumberFormatter` — because
 * symfony/polyfill-intl-icu is installed — and forbids using an internal class from
 * outside its own namespace. ext-intl *is* in the image, so the real implementation runs;
 * the polyfill has no SPELLOUT support and would never work. Confining the call here
 * means one documented suppression instead of the class leaking across the codebase.
 *
 * French spellout is exactly the thing not to hand-roll: *quatre-vingts* but
 * *quatre-vingt-un*, *soixante et onze* but *soixante-douze*. ICU knows all of it.
 */
final readonly class AmountInWords
{
    public function __construct(
        /** Kept injectable so a test can pin the locale rather than inherit one. */
        private string $locale = 'fr_FR',
    ) {
    }

    /**
     * `7247` → "soixante-douze euros et quarante-sept centimes".
     *
     * Cents are spelled out too. A receipt states an amount someone will copy onto a tax
     * return, so "et quarante-sept centimes" is worth the words — and dropping them would
     * make the letters disagree with the figures beside them.
     */
    public function forCents(int $cents): string
    {
        $euros = intdiv($cents, 100);
        $remainder = $cents % 100;

        // Singular for zéro as well as for un: French says "zéro euro", not "zéro
        // euros". Reachable for real — a receipt of a few centimes has no euros in it.
        $words = sprintf('%s %s', $this->spell($euros), $euros > 1 ? 'euros' : 'euro');

        if (0 !== $remainder) {
            $words .= sprintf(
                ' et %s %s',
                $this->spell($remainder),
                $remainder > 1 ? 'centimes' : 'centime',
            );
        }

        return ucfirst($words);
    }

    private function spell(int $number): string
    {
        // Both statements are annotated because PHPStan resolves NumberFormatter to the
        // polyfill and treats it as internal. ext-intl is what actually runs — the
        // polyfill has no SPELLOUT at all — see the class docblock.
        $formatter = new NumberFormatter($this->locale, NumberFormatter::SPELLOUT);

        /** @phpstan-ignore method.internalClass */
        $spelled = $formatter->format($number);

        if (false === $spelled) {
            // Only reachable if ext-intl is missing, in which case a receipt must not go
            // out with a blank letters line — that is a document defect, not a warning.
            throw new RuntimeException(sprintf('Could not spell out "%d" in locale "%s". Is ext-intl installed?', $number, $this->locale));
        }

        return $spelled;
    }
}
