<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Receipt\YearlyReceiptRun;
use App\State\FiscalYearState;
use DateTimeImmutable;
use Finite\StateMachine;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;

/**
 * Closing an exercice, and what that guarantees.
 *
 * The point of the state is that a rate cannot move once a reçu fiscal quotes it. So the
 * properties worth holding are: an open exercice is editable and issues nothing, a closed one is
 * frozen and issues, and reopening stops being possible the moment a receipt exists.
 *
 * Hits real Gotenberg and s3mock where a receipt has to be issued — that is the only way to reach
 * the state the reopen guard is about.
 */
final class FiscalYearStateTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private StateMachine $stateMachine;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->stateMachine = self::getContainer()->get(StateMachine::class);
    }

    #[Test]
    public function an_exercice_starts_open_and_editable(): void
    {
        $fiscalYear = FiscalYearFactory::createOne();

        self::assertSame(FiscalYearState::OPEN, $fiscalYear->getState());
        self::assertTrue($fiscalYear->isEditable());
        self::assertFalse($fiscalYear->getState()->allowsReceipts());
    }

    #[Test]
    public function closing_freezes_it_and_allows_receipts(): void
    {
        $fiscalYear = FiscalYearFactory::createOne();

        $this->stateMachine->apply($fiscalYear, FiscalYearState::TRANSITION_CLOSE);

        self::assertSame(FiscalYearState::CLOSED, $fiscalYear->getState());
        self::assertFalse($fiscalYear->isEditable());
        self::assertTrue($fiscalYear->getState()->allowsReceipts());
    }

    /**
     * Closing too early is an ordinary mistake, and a mistyped rate has to stay correctable.
     */
    #[Test]
    public function a_closed_exercice_can_be_reopened_while_no_receipt_exists(): void
    {
        $fiscalYear = FiscalYearFactory::new()->closed()->create();

        self::assertTrue($this->stateMachine->can($fiscalYear, FiscalYearState::TRANSITION_REOPEN));

        $this->stateMachine->apply($fiscalYear, FiscalYearState::TRANSITION_REOPEN);

        self::assertSame(FiscalYearState::OPEN, $fiscalYear->getState());
    }

    /**
     * And stops being reopenable once its rates have produced a tax document.
     *
     * The receipt's amount is frozen on its row, so reopening would not change the figure — it
     * would make it unreconstructible, which is worse: a volunteer may already have filed a tax
     * return quoting it.
     */
    #[Test]
    public function a_closed_exercice_cannot_be_reopened_once_it_has_priced_a_receipt(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->withSignature()->create();
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()->closed()->create();
        $this->aWaivedLine($organization, '2025-06-21');

        $report = self::getContainer()->get(YearlyReceiptRun::class)->run($organization, 2025);
        self::assertSame(1, $report->issuedCount());

        self::assertFalse($this->stateMachine->can($fiscalYear, FiscalYearState::TRANSITION_REOPEN));
    }

    /**
     * A September-to-August exercice touches two civil years but prices only the ones no earlier
     * exercice reached first — so a 2026 receipt locks 2025-2026, and a 2026-2027 exercice is
     * left alone.
     */
    #[Test]
    public function only_the_exercice_that_priced_the_year_is_locked(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->withSignature()->create();

        $pricing = FiscalYearFactory::new()->for($organization)->closed()->create([
            'name' => '2025-2026',
            'beginsOn' => new DateTimeImmutable('2025-09-01'),
            'endsOn' => new DateTimeImmutable('2026-08-31'),
            'defaultMilliEurosPerKm' => 500,
        ]);
        $later = FiscalYearFactory::new()->for($organization)->closed()->create([
            'name' => '2026-2027',
            'beginsOn' => new DateTimeImmutable('2026-09-01'),
            'endsOn' => new DateTimeImmutable('2027-08-31'),
            'defaultMilliEurosPerKm' => 700,
        ]);

        $this->aWaivedLine($organization, '2026-03-15');
        self::assertSame(1, self::getContainer()->get(YearlyReceiptRun::class)->run($organization, 2026)->issuedCount());

        self::assertFalse($this->stateMachine->can($pricing, FiscalYearState::TRANSITION_REOPEN));
        // 2026-2027 prices civil 2027, which has no receipt, so it stays reopenable.
        self::assertTrue($this->stateMachine->can($later, FiscalYearState::TRANSITION_REOPEN));
    }

    /**
     * The disabled inputs on the form are a courtesy; this is the control.
     */
    #[Test]
    public function editing_a_closed_exercice_is_refused_by_validation(): void
    {
        $fiscalYear = FiscalYearFactory::new()->closed()->create(['name' => '2025']);

        $fiscalYear->setDefaultHourlyRateCents(9999);
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($fiscalYear);

        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString('clôturé', (string) $violations->get(0)->getMessage());
    }

    #[Test]
    public function editing_an_open_exercice_is_allowed(): void
    {
        $fiscalYear = FiscalYearFactory::createOne(['name' => '2025']);

        $fiscalYear->setDefaultHourlyRateCents(9999);

        self::assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($fiscalYear));
    }

    /**
     * Reopening moves `state` on a closed exercice, so the frozen-fields validator has to let
     * that one change through or an exercice could never be reopened at all.
     */
    #[Test]
    public function reopening_itself_is_not_refused_as_an_edit(): void
    {
        $fiscalYear = FiscalYearFactory::new()->closed()->create();

        $this->stateMachine->apply($fiscalYear, FiscalYearState::TRANSITION_REOPEN);

        self::assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($fiscalYear));
    }

    private function aWaivedLine(Organization $organization, string $date): void
    {
        $person = PersonFactory::createOne(['organization' => $organization]);
        $declaration = DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        DeclarationActionFactory::new()->forDeclaration($declaration)->validated()->create([
            'date' => new DateTimeImmutable($date),
            'workHours' => '2.00',
            'journeys' => 2,
            'distanceKm' => 34,
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
        ]);
    }
}
