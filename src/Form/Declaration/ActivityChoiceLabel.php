<?php

declare(strict_types=1);

namespace App\Form\Declaration;

use App\Entity\Activity;
use DateTimeImmutable;
use IntlDateFormatter;

use function sprintf;

/**
 * How an event or action reads in the public form's select: its name, and its dates
 * when it has any — « Régate de printemps (12–13 avr. 2026) ». The dates are what tell
 * two editions of a yearly event apart.
 */
final class ActivityChoiceLabel
{
    private const string LOCALE = 'fr_FR';

    public static function for(Activity $activity): string
    {
        $begins = $activity->getBeginsOn();

        if (null === $begins) {
            return $activity->getName();
        }

        return sprintf('%s (%s)', $activity->getName(), self::period($begins, $activity->getEndsOn()));
    }

    /**
     * One date, or a range written once where the two ends agree: « 12–13 avr. 2026 »
     * within a month, « 30 mars – 2 avr. 2026 » within a year, both in full otherwise.
     */
    private static function period(DateTimeImmutable $begins, ?DateTimeImmutable $ends): string
    {
        if (null === $ends || $ends->format('Y-m-d') === $begins->format('Y-m-d')) {
            return self::format($begins, 'd MMM y');
        }

        if ($ends->format('Y-m') === $begins->format('Y-m')) {
            return self::format($begins, 'd').'–'.self::format($ends, 'd MMM y');
        }

        $pattern = $ends->format('Y') === $begins->format('Y') ? 'd MMM' : 'd MMM y';

        return self::format($begins, $pattern).' – '.self::format($ends, 'd MMM y');
    }

    private static function format(DateTimeImmutable $date, string $pattern): string
    {
        $formatter = new IntlDateFormatter(self::LOCALE, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $date->getTimezone(), null, $pattern);

        // PHPStan resolves IntlDateFormatter to Symfony's polyfill and treats it as
        // internal; ext-intl is what runs, as for App\Receipt\AmountInWords.
        /** @phpstan-ignore method.internalClass */
        return (string) $formatter->format($date);
    }
}
