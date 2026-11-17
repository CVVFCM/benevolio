<?php

declare(strict_types=1);

namespace App\ValueObject;

use function abs;
use function intdiv;
use function number_format;
use function sprintf;
use function str_repeat;

/**
 * An integer count of a fixed fraction — cents, hundredths of an hour, millièmes —
 * written the French way: comma, and thousands grouped by a narrow no-break space.
 *
 * Integer arithmetic only, no float on the way: `7247` with two decimals is "72,47",
 * exactly. The sign is written once, in front, so -50 cents reads "-0,50" — an
 * `intdiv()` alone gives 0 and loses it.
 *
 * Internal to the value objects; nothing else formats a number.
 */
final readonly class FrenchDecimal
{
    /** Between thousands: narrow, and no-break, so "1 234,56" never wraps. */
    private const string GROUP_SEPARATOR = "\u{202f}";

    public static function format(int $value, int $decimals, bool $grouped = true): string
    {
        $divisor = (int) ('1'.str_repeat('0', $decimals));
        $magnitude = abs($value);

        return sprintf(
            '%s%s,%0'.$decimals.'d',
            $value < 0 ? '-' : '',
            number_format(intdiv($magnitude, $divisor), 0, ',', $grouped ? self::GROUP_SEPARATOR : ''),
            $magnitude % $divisor,
        );
    }
}
