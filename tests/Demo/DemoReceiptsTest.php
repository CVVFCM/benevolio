<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\DemoReset;
use App\Entity\Receipt;
use App\Receipt\YearlyReceiptRun;
use App\Tests\Support\ReloadsEntities;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;

/**
 * A receipt run on the demo issues the PDFs and mails none of them: the volunteers' addresses
 * are whatever a visitor typed. Real Gotenberg and s3mock, like every receipt test.
 */
final class DemoReceiptsTest extends KernelTestCase
{
    use Factories;
    use ReloadsEntities;
    use ResetDatabase;

    #[Test]
    public function the_demo_issues_receipts_and_mails_none(): void
    {
        $organization = self::getContainer()->get(DemoReset::class)->reset()->organization;
        // The year of the sample's latest validated journey, 12 days back. The other one, 30 days
        // back, falls in the same year except in January: one receipt or two.
        $year = (int) new DateTimeImmutable('-12 days')->format('Y');

        self::getContainer()->get(YearlyReceiptRun::class)->run($organization, $year);

        self::assertGreaterThanOrEqual(1, count($this->findAllOf(Receipt::class, ['organization' => $organization])));
        self::assertEmailCount(0);
    }
}
