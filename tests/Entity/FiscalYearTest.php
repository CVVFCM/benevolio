<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FiscalYear;
use App\Enum\FiscalPower;
use App\Factory\FiscalYearFactory;
use App\Factory\FiscalYearTaskRateFactory;
use App\Factory\OrganizationFactory;
use App\Factory\TaskFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;

/**
 * The exercice comptable: its bounds, and the rates that hang off it.
 *
 * Overlap is the invariant that matters most. A contribution belongs to an exercice by
 * date, so two overlapping exercices would each claim the same lines and both ledgers
 * would be wrong with nothing on either page to say so.
 */
final class FiscalYearTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    #[Test]
    public function it_contains_its_own_days_and_no_others(): void
    {
        $fiscalYear = FiscalYearFactory::new()->calendarYear(2025)->create();

        // Both bounds are inclusive: an exercice runs to the end of its last day.
        self::assertTrue($fiscalYear->contains(new DateTimeImmutable('2025-01-01')));
        self::assertTrue($fiscalYear->contains(new DateTimeImmutable('2025-12-31')));
        self::assertTrue($fiscalYear->contains(new DateTimeImmutable('2025-06-15')));
        self::assertFalse($fiscalYear->contains(new DateTimeImmutable('2024-12-31')));
        self::assertFalse($fiscalYear->contains(new DateTimeImmutable('2026-01-01')));
    }

    /**
     * A time component would make the last day of the exercice fall outside it.
     */
    #[Test]
    public function a_time_of_day_does_not_push_a_date_out_of_the_exercice(): void
    {
        $fiscalYear = FiscalYearFactory::new()->calendarYear(2025)->create();

        self::assertTrue($fiscalYear->contains(new DateTimeImmutable('2025-12-31 23:30')));
    }

    #[Test]
    public function an_exercice_must_end_after_it_begins(): void
    {
        $fiscalYear = new FiscalYear(OrganizationFactory::createOne());
        $fiscalYear->setName('à rebours');
        $fiscalYear->setBeginsOn(new DateTimeImmutable('2025-12-31'));
        $fiscalYear->setEndsOn(new DateTimeImmutable('2025-01-01'));

        $violations = $this->validator->validate($fiscalYear);

        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString('doit suivre son début', (string) $violations->get(0)->getMessage());
    }

    #[Test]
    public function two_exercices_of_one_association_cannot_overlap(): void
    {
        $organization = OrganizationFactory::createOne();
        FiscalYearFactory::new()->for($organization)->calendarYear(2025)->create();

        $overlapping = new FiscalYear($organization);
        $overlapping->setName('2025 bis');
        $overlapping->setBeginsOn(new DateTimeImmutable('2025-06-01'));
        $overlapping->setEndsOn(new DateTimeImmutable('2026-05-31'));

        $violations = $this->validator->validate($overlapping);

        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString('chevauche', (string) $violations->get(0)->getMessage());
        // The message has to name the clash, or a treasurer cannot act on it.
        self::assertStringContainsString('2025', (string) $violations->get(0)->getMessage());
    }

    /**
     * 31 December then 1 January is the ordinary case, and must not be refused.
     */
    #[Test]
    public function consecutive_exercices_are_not_an_overlap(): void
    {
        $organization = OrganizationFactory::createOne();
        FiscalYearFactory::new()->for($organization)->calendarYear(2025)->create();

        $next = new FiscalYear($organization);
        $next->setName('2026');
        $next->setBeginsOn(new DateTimeImmutable('2026-01-01'));
        $next->setEndsOn(new DateTimeImmutable('2026-12-31'));

        self::assertCount(0, $this->validator->validate($next));
    }

    /**
     * Two associations keeping the same calendar year is normal, not a clash. The
     * validator passes the organization explicitly for exactly this reason —
     * OrganizationFilter is off in a CLI or test context, so relying on it would make
     * one association's exercice collide with another's.
     */
    #[Test]
    public function another_associations_exercice_is_not_an_overlap(): void
    {
        FiscalYearFactory::new()
            ->for(OrganizationFactory::createOne())
            ->calendarYear(2025)
            ->create();

        $mine = new FiscalYear(OrganizationFactory::createOne());
        $mine->setName('2025');
        $mine->setBeginsOn(new DateTimeImmutable('2025-01-01'));
        $mine->setEndsOn(new DateTimeImmutable('2025-12-31'));

        self::assertCount(0, $this->validator->validate($mine));
    }

    #[Test]
    public function editing_an_exercice_does_not_clash_with_itself(): void
    {
        $fiscalYear = FiscalYearFactory::new()->calendarYear(2025)->create();

        $fiscalYear->setEndsOn(new DateTimeImmutable('2025-11-30'));

        self::assertCount(0, $this->validator->validate($fiscalYear));
    }

    #[Test]
    public function the_default_rate_applies_when_nothing_overrides_it(): void
    {
        $organization = OrganizationFactory::createOne();
        $task = TaskFactory::new()->for($organization)->create();
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->create(['defaultHourlyRateCents' => 1500, 'defaultMilliEurosPerKm' => 529]);

        self::assertSame(1500, $fiscalYear->hourlyRateCentsFor($task));
        self::assertSame(529, $fiscalYear->milliEurosPerKmFor(FiscalPower::FIVE_CV));
    }

    #[Test]
    public function an_override_applies_only_to_its_own_type(): void
    {
        $organization = OrganizationFactory::createOne();
        $priced = TaskFactory::new()->for($organization)->create();
        $unpriced = TaskFactory::new()->for($organization)->create();
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()
            ->create(['defaultHourlyRateCents' => 1500]);

        FiscalYearTaskRateFactory::new()
            ->forTask($fiscalYear, $priced)
            ->create(['hourlyRateCents' => 3000]);

        self::assertSame(3000, $fiscalYear->hourlyRateCentsFor($priced));
        self::assertSame(1500, $fiscalYear->hourlyRateCentsFor($unpriced), 'The override leaked to another task.');

        // The published barème, per bracket — not the single default.
        self::assertSame(529, $fiscalYear->milliEurosPerKmFor(FiscalPower::THREE_CV_OR_LESS));
        self::assertSame(636, $fiscalYear->milliEurosPerKmFor(FiscalPower::FIVE_CV));
        self::assertSame(697, $fiscalYear->milliEurosPerKmFor(FiscalPower::SEVEN_CV_OR_MORE));
    }
}
