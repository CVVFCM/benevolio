<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Activity;
use App\Entity\Organization;
use App\Form\Declaration\ActivityChoiceLabel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How an activity reads in the public select. Fixed dates, for once: the subject is the
 * wording of a date, which a relative one could not pin down.
 */
final class ActivityChoiceLabelTest extends TestCase
{
    #[Test]
    #[DataProvider('periods')]
    public function it_writes_the_dates_beside_the_name(?string $beginsOn, ?string $endsOn, string $expected): void
    {
        $activity = new Activity(new Organization())
            ->setName('Régate')
            ->setBeginsOn(null === $beginsOn ? null : new DateTimeImmutable($beginsOn))
            ->setEndsOn(null === $endsOn ? null : new DateTimeImmutable($endsOn));

        self::assertSame($expected, ActivityChoiceLabel::for($activity));
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function periods(): iterable
    {
        yield 'undated' => [null, null, 'Régate'];
        yield 'one day' => ['2026-04-12', null, 'Régate (12 avr. 2026)'];
        yield 'same day twice' => ['2026-04-12', '2026-04-12', 'Régate (12 avr. 2026)'];
        yield 'within a month' => ['2026-04-12', '2026-04-13', 'Régate (12–13 avr. 2026)'];
        yield 'across months' => ['2026-03-30', '2026-04-02', 'Régate (30 mars – 2 avr. 2026)'];
        yield 'across years' => ['2025-12-30', '2026-01-02', 'Régate (30 déc. 2025 – 2 janv. 2026)'];
    }
}
