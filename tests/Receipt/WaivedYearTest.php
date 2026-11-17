<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Entity\DeclarationAction;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Receipt\WaivedYear;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The date a receipt prints in « Date du versement ou du don ».
 */
final class WaivedYearTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function the_date_is_the_last_day_of_the_latest_waived_line(): void
    {
        $waived = new WaivedYear($this->lineOn('2025-06-20', days: 1)->getDeclaration()->getPerson(), 2025);

        $waived->add($this->lineOn('2025-06-20', days: 1), 4325);
        $waived->add($this->lineOn('2025-09-05', days: 3), 4325);
        $waived->add($this->lineOn('2025-07-14', days: 1), 4325);

        self::assertSame('2025-09-07', $waived->lastWaivedDay()->format('Y-m-d'));
    }

    /**
     * A line belongs to the year it starts in, wholly — so one starting on 30 December
     * and lasting three days is on the 2025 receipt, and used to date that receipt
     * 1 January 2026: a donation dated outside the year the document covers.
     */
    #[Test]
    public function a_line_running_past_new_year_dates_the_receipt_31_december(): void
    {
        $line = $this->lineOn('2025-12-30', days: 3);
        $waived = new WaivedYear($line->getDeclaration()->getPerson(), 2025);

        $waived->add($line, 4325);

        self::assertSame('2026-01-01', $line->getEndDate()->format('Y-m-d'));
        self::assertSame('2025-12-31', $waived->lastWaivedDay()->format('Y-m-d'));
    }

    private function lineOn(string $date, int $days): DeclarationAction
    {
        $declaration = DeclarationFactory::new()->for(OrganizationFactory::createOne())->confirmed()->create();

        return DeclarationActionFactory::new()->forDeclaration($declaration)->create([
            'date' => new DateTimeImmutable($date),
            'consecutiveDays' => $days,
        ]);
    }
}
