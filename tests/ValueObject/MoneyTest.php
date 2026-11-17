<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\ValueObject\HourlyRate;
use App\ValueObject\MileageRate;
use App\ValueObject\Money;
use App\ValueObject\WorkHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The four figures the domain hands out, and the one way each is written: comma, narrow
 * no-break space between thousands, no-break space before the unit.
 */
final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'cents only' => [7, "0,07\u{a0}€"];
        yield 'ordinary' => [7247, "72,47\u{a0}€"];
        yield 'thousands are grouped' => [123456, "1\u{202f}234,56\u{a0}€"];
        yield 'zero' => [0, "0,00\u{a0}€"];
        // intdiv(-50, 100) is 0: written naively, the sign is lost.
        yield 'under one euro, negative' => [-50, "-0,50\u{a0}€"];
        yield 'negative' => [-123456, "-1\u{202f}234,56\u{a0}€"];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function an_amount_is_written_the_french_way(int $cents, string $expected): void
    {
        self::assertSame($expected, new Money($cents)->format());
        self::assertSame($expected, (string) new Money($cents));
    }

    #[Test]
    public function the_cerfa_amount_has_no_sign_and_no_grouping(): void
    {
        self::assertSame('1234,56', new Money(123456)->formatBare());
        self::assertSame('43,25', new Money(4325)->formatBare());
    }

    #[Test]
    public function amounts_add_up_exactly(): void
    {
        $total = Money::zero()->plus(new Money(4325))->plus(new Money(1));

        self::assertSame(4326, $total->cents);
        self::assertTrue($total->isPositive());
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse(new Money(-1)->isPositive());
    }

    #[Test]
    public function rates_carry_their_unit(): void
    {
        self::assertSame("12,00\u{a0}€/h", new HourlyRate(1200)->format());
        // Three decimals: the barème is published in millièmes.
        self::assertSame("0,529\u{a0}€/km", new MileageRate(529, 20)->format());
        self::assertSame("1,005\u{a0}€/km", new MileageRate(1005, 20)->format());
        self::assertSame("0,636\u{a0}€/km, +20\u{a0}% électrique", new MileageRate(636, 20)->formatWithElectricBonus());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimals(): iterable
    {
        yield 'as PostgreSQL returns it' => ['7.50', 750];
        yield 'a bare integer assigned in PHP' => ['7', 700];
        yield 'one decimal' => ['6.5', 650];
        yield 'a quarter' => ['0.25', 25];
    }

    #[Test]
    #[DataProvider('decimals')]
    public function hours_are_read_from_the_decimal_column_exactly(string $decimal, int $hundredths): void
    {
        self::assertSame($hundredths, WorkHours::fromDecimal($decimal)->hundredths);
    }

    #[Test]
    public function hours_are_written_with_a_comma(): void
    {
        self::assertSame("7,50\u{a0}h", new WorkHours(750)->format());
        self::assertSame("1\u{202f}275,00\u{a0}h", new WorkHours(127500)->format());
        self::assertSame(575, new WorkHours(225)->plus(new WorkHours(350))->hundredths);
    }
}
