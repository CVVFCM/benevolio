<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\ContributionValuator;
use App\Entity\DeclarationAction;
use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Entity\Task;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\FiscalYearTaskRateFactory;
use App\Factory\OrganizationFactory;
use App\Repository\FiscalYearRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The arithmetic that ends up on a tax receipt.
 *
 * Every expected figure below is computed by hand in the test name or comment, not by
 * repeating the implementation — a test that multiplies the same way the code does
 * proves only that multiplication is deterministic.
 */
final class ContributionValuatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ContributionValuator $valuator;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Built by hand, not fetched: nothing injects ContributionValuator until the
        // ledger page does, so the container inlines it away — the same reason
        // DeclarationDeciderTest constructs its subject.
        $this->valuator = new ContributionValuator(
            self::getContainer()->get(FiscalYearRepository::class),
        );
    }

    /**
     * 0,529 €/km is the 3 CV et moins figure from the arrêté du 27 mars 2023.
     *
     * @return iterable<string, array{int, int, int}>
     */
    public static function mileageCases(): iterable
    {
        // km, millièmes per km, expected cents
        yield '137 km at 0,529 = 72,473 € rounds to 72,47 €' => [137, 529, 7247];
        yield '100 km at 0,529 = 52,90 € exactly' => [100, 529, 5290];
        // 15 × 529 = 7935 tenths of a cent = 793,5 → half rounds up, not down.
        yield '15 km at 0,529 = 7,935 € rounds up to 7,94 €' => [15, 529, 794];
        yield '1 km at 0,697 rounds to 0,07 €' => [1, 697, 70];
        yield 'no distance is no money' => [0, 529, 0];
    }

    #[Test]
    #[DataProvider('mileageCases')]
    public function it_values_mileage_from_the_bareme(int $km, int $milliEuros, int $expectedCents): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->create(['defaultMilliEurosPerKm' => $milliEuros]);
        $action = $this->actionOn($organization, '2025-06-15', [
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::THREE_CV_OR_LESS,
            'journeys' => 1,
            'distanceKm' => $km,
        ]);

        $valuation = $this->valuator->valueWithin($action, $fiscalYear);

        self::assertSame($expectedCents, $valuation->mileageCents);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function hourCases(): iterable
    {
        // workHours, cents per hour, expected cents
        yield '7,25 h at 12,00 € = 87,00 €' => ['7.25', 1200, 8700];
        yield '1,50 h at 12,00 € = 18,00 €' => ['1.50', 1200, 1800];
        // 33 × 1250 / 100 = 412,5 → half rounds up.
        yield '0,33 h at 12,50 € = 4,125 € rounds up to 4,13 €' => ['0.33', 1250, 413];
        yield '0,01 h at 12,00 € = 0,12 €' => ['0.01', 1200, 12];
    }

    #[Test]
    #[DataProvider('hourCases')]
    public function it_values_donated_hours(string $workHours, int $rateCents, int $expectedCents): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->create(['defaultHourlyRateCents' => $rateCents]);
        $action = $this->actionOn($organization, '2025-06-15', ['workHours' => $workHours]);

        $valuation = $this->valuator->valueWithin($action, $fiscalYear);

        self::assertSame($expectedCents, $valuation->hoursCents);
    }

    #[Test]
    public function a_line_no_exercice_covers_is_not_valued(): void
    {
        $organization = OrganizationFactory::createOne();
        FiscalYearFactory::new()->for($organization)->calendarYear(2025)->create();
        // 2024 — before the only exercice this association has created.
        $action = $this->actionOn($organization, '2024-06-15');

        // Null, not a zero-valued result: there is no barème for the period, so there
        // is no figure to state.
        self::assertNull($this->valuator->value($action));
    }

    #[Test]
    public function travel_without_the_volunteers_own_vehicle_is_worth_nothing(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = $this->year2025($organization);
        $action = $this->actionOn($organization, '2025-06-15', [
            'ownVehicle' => false,
            'fiscalPower' => null,
            'journeys' => 4,
            'distanceKm' => 50,
        ]);

        $valuation = $this->valuator->valueWithin($action, $fiscalYear);

        // The barème values a vehicle the volunteer owns. A lift from someone else
        // costs them nothing to waive.
        self::assertSame(0, $valuation->mileageCents);
    }

    /**
     * The distance is per journey, one way, so the total is what gets valued.
     */
    #[Test]
    public function the_total_distance_is_valued_not_one_journey(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->create(['defaultMilliEurosPerKm' => 529]);
        // 20 km × 4 journeys = 80 km; 80 × 529 = 42 320 tenths of a cent = 42,32 €.
        $action = $this->actionOn($organization, '2025-06-15', [
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::THREE_CV_OR_LESS,
            'journeys' => 4,
            'distanceKm' => 20,
        ]);

        self::assertSame(4232, $this->valuator->valueWithin($action, $fiscalYear)->mileageCents);
    }

    #[Test]
    public function a_per_task_override_beats_the_default(): void
    {
        $organization = OrganizationFactory::createOne();
        // One of the tasks every association is seeded with, rather than a new one:
        // "Arbitrage" already exists here, and TaskFactory would collide with the
        // unique index on (organization, name).
        $task = $this->entityManager->getRepository(Task::class)
            ->findOneBy(['organization' => $organization, 'name' => 'Arbitrage']);
        self::assertNotNull($task);
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->create(['defaultHourlyRateCents' => 1200]);
        FiscalYearTaskRateFactory::new()
            ->forTask($fiscalYear, $task)
            ->create(['hourlyRateCents' => 2500]);

        $declaration = DeclarationFactory::new()->for($organization)->create();
        $action = DeclarationActionFactory::new()->forDeclaration($declaration)->create([
            'task' => $task,
            'date' => new DateTimeImmutable('2025-06-15'),
            'workHours' => '2.00',
        ]);

        // 2 h at 25,00 €, not at the 12,00 € default.
        self::assertSame(5000, $this->valuator->valueWithin($action, $fiscalYear)->hoursCents);
    }

    private function year2025(Organization $organization): FiscalYear
    {
        return FiscalYearFactory::new()
            ->for($organization)
            ->calendarYear(2025)
            ->create()
        ;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function actionOn(Organization $organization, string $date, array $attributes = []): DeclarationAction
    {
        $declaration = DeclarationFactory::new()->for($organization)->create();

        return DeclarationActionFactory::new()
            ->forDeclaration($declaration)
            ->create([...$attributes, 'date' => new DateTimeImmutable($date)])
        ;
    }
}
