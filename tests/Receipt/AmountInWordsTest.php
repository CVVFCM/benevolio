<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Receipt\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The « somme en toutes lettres » beside the figure on the CERFA.
 *
 * French is the reason this delegates to ICU instead of being hand-rolled, so the cases
 * below are the ones a hand-rolled version gets wrong.
 */
final class AmountInWordsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'a plain amount with cents' => [7247, 'Soixante-douze euros et quarante-sept centimes'];
        yield 'no cents, no mention of them' => [5290, 'Cinquante-deux euros et quatre-vingt-dix centimes'];

        // Eighty takes an s; eighty-one does not.
        yield 'quatre-vingts keeps its s' => [8000, 'Quatre-vingts euros'];
        yield 'quatre-vingt-un loses it' => [8100, 'Quatre-vingt-un euros'];

        // Seventy-one is soixante et onze; seventy-two is not soixante et douze.
        yield 'soixante et onze' => [7100, 'Soixante-et-onze euros'];
        yield 'soixante-douze' => [7200, 'Soixante-douze euros'];

        // Singular for one, and for zero — French says "zéro euro".
        yield 'one euro is singular' => [100, 'Un euro'];
        yield 'one centime is singular' => [1, 'Zéro euro et un centime'];
        yield 'zero euros is singular too' => [21, 'Zéro euro et vingt-et-un centimes'];

        yield 'past a thousand' => [153000, 'Mille cinq cent trente euros'];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function it_spells_an_amount_in_french(int $cents, string $expected): void
    {
        self::assertSame($expected, new AmountInWords()->forCents($cents));
    }
}
