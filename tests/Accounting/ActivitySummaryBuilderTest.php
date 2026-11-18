<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\ActivitySummary;
use App\Accounting\ActivitySummaryBuilder;
use App\Entity\Activity;
use App\Entity\Organization;
use App\Entity\Person;
use App\Enum\FiscalPower;
use App\Factory\ActivityFactory;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * What the validated lines given for an event or action add up to (#47).
 *
 * Expected figures are worked out by hand: 10 €/h and 0,500 €/km in 2025, 20 €/h and
 * 1,000 €/km in 2024, so each line's amount is readable at a glance.
 */
final class ActivitySummaryBuilderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Organization $organization;
    private Activity $regatta;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->organization = OrganizationFactory::createOne();
        $this->regatta = ActivityFactory::new()->for($this->organization)->create(['name' => 'Régate']);

        FiscalYearFactory::new()->for($this->organization)->calendarYear(2025)
            ->create(['defaultHourlyRateCents' => 1000, 'defaultMilliEurosPerKm' => 500]);
        FiscalYearFactory::new()->for($this->organization)->calendarYear(2024)
            ->create(['defaultHourlyRateCents' => 2000, 'defaultMilliEurosPerKm' => 1000]);
    }

    #[Test]
    public function it_adds_up_hours_valued_kilometres_and_the_two_amounts(): void
    {
        $camille = $this->volunteer();
        // 3 h → 30 €; 2 × 10 km in their own car → 20 km, 10 €.
        $this->line($camille, '2025-05-10', ['workHours' => '3.00', 'journeys' => 2, 'distanceKm' => 10, 'ownVehicle' => true, 'fiscalPower' => FiscalPower::FIVE_CV]);
        // 1,5 h → 15 €; no travel.
        $this->line($camille, '2025-05-11', ['workHours' => '1.50', 'journeys' => 0, 'distanceKm' => 0]);

        $summary = $this->summaryOf($this->regatta);

        self::assertSame(450, $summary->hours->hundredths);
        self::assertSame(20, $summary->valuedKm);
        self::assertSame(4500, $summary->hoursAmount->cents);
        self::assertSame(1000, $summary->mileageAmount->cents);
        self::assertSame(1, $summary->volunteers);
    }

    #[Test]
    public function kilometres_as_a_passenger_are_declared_but_not_counted(): void
    {
        $this->line($this->volunteer(), '2025-05-10', ['workHours' => '1.00', 'journeys' => 2, 'distanceKm' => 40, 'ownVehicle' => false]);

        $summary = $this->summaryOf($this->regatta);

        self::assertSame(0, $summary->valuedKm);
        self::assertSame(0, $summary->mileageAmount->cents);
        self::assertSame(1000, $summary->hoursAmount->cents);
    }

    #[Test]
    public function an_electric_vehicle_gets_the_bonus(): void
    {
        // 100 km at 0,500 € + 20 % → 60 €.
        $this->line($this->volunteer(), '2025-05-10', [
            'workHours' => '1.00', 'journeys' => 2, 'distanceKm' => 50,
            'ownVehicle' => true, 'fiscalPower' => FiscalPower::FIVE_CV, 'electricVehicle' => true,
        ]);

        self::assertSame(6000, $this->summaryOf($this->regatta)->mileageAmount->cents);
    }

    #[Test]
    public function only_validated_lines_count(): void
    {
        $camille = $this->volunteer();
        $this->line($camille, '2025-05-10', ['workHours' => '2.00']);

        $declaration = DeclarationFactory::new()->forPerson($camille)->confirmed()->create();
        // Confirmed, not ruled on.
        DeclarationActionFactory::new()->forDeclaration($declaration)->forActivity($this->regatta)->confirmed()->onFoot()
            ->create(['date' => new DateTimeImmutable('2025-05-12'), 'workHours' => '8.00']);
        // Never confirmed.
        DeclarationActionFactory::new()->forDeclaration($declaration)->forActivity($this->regatta)->onFoot()
            ->create(['date' => new DateTimeImmutable('2025-05-13'), 'workHours' => '8.00']);

        self::assertSame(200, $this->summaryOf($this->regatta)->hours->hundredths);
    }

    #[Test]
    public function volunteers_are_counted_once_each(): void
    {
        $camille = $this->volunteer();
        $this->line($camille, '2025-05-10', []);
        $this->line($camille, '2025-05-11', []);
        $this->line($this->volunteer(), '2025-05-10', []);

        self::assertSame(2, $this->summaryOf($this->regatta)->volunteers);
    }

    /**
     * An activity has no exercice of its own: each line takes the rates of the exercice
     * its own date falls in.
     */
    #[Test]
    public function each_line_is_priced_by_the_exercice_of_its_own_date(): void
    {
        $camille = $this->volunteer();
        // 2025: 1 h → 10 €. 2024: 1 h → 20 €.
        $this->line($camille, '2025-03-01', ['workHours' => '1.00']);
        $this->line($camille, '2024-03-01', ['workHours' => '1.00']);

        self::assertSame(3000, $this->summaryOf($this->regatta)->hoursAmount->cents);
    }

    /**
     * No exercice, no rate, no amount — but the hours were given and the kilometres driven.
     */
    #[Test]
    public function a_line_no_exercice_covers_counts_hours_and_kilometres_but_no_amount(): void
    {
        $this->line($this->volunteer(), '2023-06-01', [
            'workHours' => '4.00', 'journeys' => 2, 'distanceKm' => 15,
            'ownVehicle' => true, 'fiscalPower' => FiscalPower::FIVE_CV,
        ]);

        $summary = $this->summaryOf($this->regatta);

        self::assertSame(400, $summary->hours->hundredths);
        self::assertSame(30, $summary->valuedKm);
        self::assertSame(1, $summary->volunteers);
        self::assertSame(0, $summary->hoursAmount->cents);
        self::assertSame(0, $summary->mileageAmount->cents);
    }

    #[Test]
    public function lines_are_kept_apart_by_activity_and_free_titles_belong_to_none(): void
    {
        $camille = $this->volunteer();
        $upkeep = ActivityFactory::new()->for($this->organization)->create(['name' => 'Entretien']);
        $this->line($camille, '2025-05-10', ['workHours' => '2.00']);
        $this->line($camille, '2025-05-11', ['workHours' => '5.00'], $upkeep);
        DeclarationActionFactory::new()
            ->forDeclaration(DeclarationFactory::new()->forPerson($camille)->create())
            ->onFoot()->validated()
            ->create(['date' => new DateTimeImmutable('2025-05-12'), 'title' => 'Coup de main', 'workHours' => '9.00']);

        $summaries = $this->summaries($this->organization);

        self::assertCount(2, $summaries);
        self::assertSame(200, $this->summaryOf($this->regatta)->hours->hundredths);
        self::assertSame(500, $this->summaryOf($upkeep)->hours->hundredths);
    }

    #[Test]
    public function another_associations_lines_are_not_counted(): void
    {
        $theirs = OrganizationFactory::createOne();
        $theirActivity = ActivityFactory::new()->for($theirs)->create();
        $this->line(PersonFactory::new()->for($theirs)->create(), '2025-05-10', [], $theirActivity);

        self::assertSame([], $this->summaries($this->organization));
        self::assertCount(1, $this->summaries($theirs));
    }

    #[Test]
    public function an_activity_without_a_validated_line_has_no_entry(): void
    {
        self::assertSame([], $this->summaries($this->organization));
        self::assertSame(0, ActivitySummary::empty()->volunteers);
    }

    private function volunteer(): Person
    {
        return PersonFactory::new()->for($this->organization)->create();
    }

    /**
     * One validated line for the activity, on foot unless told otherwise.
     *
     * @param array<string, mixed> $attributes
     */
    private function line(Person $person, string $date, array $attributes, ?Activity $activity = null): void
    {
        DeclarationActionFactory::new()
            ->forDeclaration(DeclarationFactory::new()->forPerson($person)->create())
            ->forActivity($activity ?? $this->regatta)
            ->onFoot()
            ->validated()
            ->create([...$attributes, 'date' => new DateTimeImmutable($date)]);
    }

    private function summaryOf(Activity $activity): ActivitySummary
    {
        return $this->summaries($activity->getOrganization())[$activity->getId()->toRfc4122()] ?? ActivitySummary::empty();
    }

    /**
     * @return array<string, ActivitySummary>
     */
    private function summaries(Organization $organization): array
    {
        // Cleared: Foundry leaves inverse sides stale, and the builder reads through them.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(ActivitySummaryBuilder::class)->forOrganization($organization);
    }
}
