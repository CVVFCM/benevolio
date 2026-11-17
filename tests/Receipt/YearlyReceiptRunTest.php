<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\Receipt;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Receipt\ReceiptRunOutcome;
use App\Receipt\ReceiptRunReport;
use App\Receipt\YearlyReceiptRun;
use App\Tests\Support\FailingGotenberg;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function array_map;

/**
 * Issuing a whole civil year of reçus fiscaux.
 *
 * The properties that matter are the ones the old per-declaration model got wrong: a
 * volunteer gets **one** receipt for the year however many declarations they filed, the
 * amount is the abandon de frais and nothing else, and the exercice's shape does not decide
 * the period.
 *
 * Hits **real Gotenberg and real s3mock**, like ReceiptGeneratorTest: the run's job is to
 * turn contributions into a stored, numbered, emailed document, and a mocked storage would
 * prove none of it. Needs `make up`.
 */
final class YearlyReceiptRunTest extends KernelTestCase
{
    use Factories;
    use FailingGotenberg;
    use ResetDatabase;

    private YearlyReceiptRun $run;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->run = self::getContainer()->get(YearlyReceiptRun::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The reason this lot exists: four declarations, one receipt.
     */
    #[Test]
    public function every_declaration_of_a_year_lands_on_one_receipt(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);

        // Four separate declarations, 34 km each in the volunteer's own vehicle at the 5 CV
        // rate of 0,636 → 4 × 43,25 € = 173,00 €. (68 km per declaration: 2 journeys of 34.)
        foreach (['2025-02-10', '2025-04-20', '2025-06-21', '2025-11-05'] as $date) {
            $this->waivedLine($person, $date);
        }

        $report = $this->run->run($organization, 2025);

        self::assertSame(1, $report->issuedCount());
        self::assertSame(17300, $report->totalCents());

        $receipt = $this->firstIssued($report);
        self::assertSame(2025, $receipt->getYear());
        self::assertSame($person->getId(), $receipt->getPerson()->getId());
        self::assertSame('0001', $receipt->getNumber());
    }

    /**
     * A civil year straddling two exercices is priced from both, which is the whole point of
     * pricing each line by the exercice covering its own date.
     */
    #[Test]
    public function a_year_spanning_two_exercices_is_priced_from_both(): void
    {
        $organization = $this->association();
        $person = PersonFactory::createOne(['organization' => $organization]);

        // September-to-August exercices, so 2025 falls across two of them — and they carry
        // different rates, so the amount proves which one priced what.
        FiscalYearFactory::new()->for($organization)->create([
            'name' => '2024-2025',
            'beginsOn' => new DateTimeImmutable('2024-09-01'),
            'endsOn' => new DateTimeImmutable('2025-08-31'),
            'defaultMilliEurosPerKm' => 500,
        ]);
        FiscalYearFactory::new()->for($organization)->create([
            'name' => '2025-2026',
            'beginsOn' => new DateTimeImmutable('2025-09-01'),
            'endsOn' => new DateTimeImmutable('2026-08-31'),
            'defaultMilliEurosPerKm' => 700,
        ]);

        // 68 km in each half: 68 × 0,500 = 34,00 €, then 68 × 0,700 = 47,60 €.
        // No withPublishedBareme() above, so no per-power override exists and
        // FiscalYear::milliEurosPerKmFor() falls back to each exercice's default rate.
        $this->waivedLine($person, '2025-03-15');
        $this->waivedLine($person, '2025-10-15');

        $report = $this->run->run($organization, 2025);

        self::assertSame(1, $report->issuedCount());
        self::assertSame(3400 + 4760, $report->totalCents());
    }

    #[Test]
    public function only_the_chosen_year_is_counted(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2024);
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);

        $this->waivedLine($person, '2024-06-21');
        $this->waivedLine($person, '2025-06-21');

        self::assertSame(4325, $this->run->run($organization, 2025)->totalCents());
    }

    /**
     * Hours only: nothing waived, so nothing to receipt. An ordinary outcome, reported by
     * name rather than passed over in silence.
     */
    #[Test]
    public function a_volunteer_who_waived_nothing_is_skipped_with_a_reason(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);

        $declaration = DeclarationFactory::new()->forPerson($person)->confirmed()->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->validated()->create([
            'date' => new DateTimeImmutable('2025-06-21'),
            'workHours' => '7.00',
            'journeys' => 0,
            'distanceKm' => 0,
            'ownVehicle' => false,
            'fiscalPower' => null,
        ]);

        $report = $this->run->run($organization, 2025);

        self::assertSame(0, $report->issuedCount());
        self::assertSame(1, $report->skippedCount());
        $skipped = $report->skipped();
        self::assertArrayHasKey(0, $skipped);
        self::assertStringContainsString('Aucun frais abandonné', $skipped[0]->skipReason());
    }

    #[Test]
    public function a_line_no_exercice_covers_is_left_out_and_reported(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);

        $this->waivedLine($person, '2025-06-21');
        // 2026 has no exercice, but this is the 2026 run — so the only line it has cannot be
        // priced, and the volunteer is skipped rather than receipted for zero.
        $this->waivedLine($person, '2026-06-21');

        $report = $this->run->run($organization, 2026);

        self::assertSame(0, $report->issuedCount());
        self::assertSame(1, $report->unvaluedLineCount());
    }

    /**
     * The association's own identity is checked once, and nothing is attempted without it —
     * a receipt lacking the SIREN is not a valid document.
     */
    #[Test]
    public function an_association_without_a_siren_issues_nothing(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);
        $this->waivedLine($person, '2025-06-21');

        $report = $this->run->run($organization, 2025);

        self::assertTrue($report->isRefused());
        self::assertStringContainsString('SIREN', $report->refusalReason());
        self::assertSame([], $report->outcomes);
        self::assertCount(0, $this->entityManager->getRepository(Receipt::class)->findAll());
    }

    /**
     * An address typed before the form checked it — a four-digit French postcode — is
     * all there, but App\ValueObject\Address will not build from it. That used to
     * surface as an exception, so a 500 on the run, where the treasurer needs to be
     * told what to correct.
     */
    #[Test]
    public function an_association_whose_address_does_not_validate_issues_nothing(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->create(['addressPostcode' => '7500']);
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);
        $this->waivedLine($person, '2025-06-21');

        $report = $this->run->run($organization, 2025);

        self::assertTrue($report->isRefused());
        self::assertStringContainsString('adresse postale', $report->refusalReason());
        self::assertStringContainsString('n\'est pas valide', $report->refusalReason());
        self::assertCount(0, $this->entityManager->getRepository(Receipt::class)->findAll());
    }

    /**
     * Gotenberg answers for the first volunteer and is gone for the second. The run used
     * to let that exception out: a 500, with one receipt issued and mailed and nothing
     * saying so. It stops instead, and reports all three volunteers.
     */
    #[Test]
    public function a_receipt_that_cannot_be_made_stops_the_run_and_is_reported(): void
    {
        $run = $this->runWhoseRendererFailsAfter(1);
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $first = PersonFactory::createOne(['organization' => $organization, 'lastName' => 'Premier']);
        $second = PersonFactory::createOne(['organization' => $organization, 'lastName' => 'Deuxième']);
        $third = PersonFactory::createOne(['organization' => $organization, 'lastName' => 'Troisième']);
        $this->waivedLine($first, '2025-02-10');
        $this->waivedLine($second, '2025-03-10');
        $this->waivedLine($third, '2025-04-10');

        $report = $run->run($organization, 2025);

        self::assertTrue($report->isInterrupted());
        self::assertSame(['Premier'], $this->lastNames($report->issued()));
        $failure = $report->failure();
        self::assertNotNull($failure);
        self::assertSame('Deuxième', $failure->person->getLastName());
        self::assertStringContainsString('erreur technique', $failure->failureReason());
        self::assertSame(['Troisième'], $this->lastNames($report->notAttempted()));
        self::assertSame([], $report->skipped());
        self::assertSame(4325, $report->totalCents());
    }

    /**
     * Nothing half-made is left behind for the volunteer it failed on: no row, and no
     * number taken — the next receipt issued continues the series without a gap.
     */
    #[Test]
    public function a_failed_receipt_takes_no_number(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $this->waivedLine(PersonFactory::createOne(['organization' => $organization]), '2025-02-10');

        $report = $this->runWhoseRendererFailsAfter(0)->run($organization, 2025);

        self::assertTrue($report->isInterrupted());
        self::assertSame([], $report->issued());

        // The failed transaction closed the EntityManager; a new request gets a new one.
        self::ensureKernelShutdown();
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $entityManager->getRepository(Receipt::class)->findAll());

        $organization = $entityManager->getRepository(Organization::class)->find($organization->getId());
        self::assertNotNull($organization);
        $again = self::getContainer()->get(YearlyReceiptRun::class)->run($organization, 2025);

        self::assertSame('0001', $this->firstIssued($again)->getNumber());
    }

    /**
     * Someone who waived nothing is skipped for that reason, interrupted run or not: no
     * receipt was ever owed, so "not attempted" would be a wrong thing to tell a treasurer.
     */
    #[Test]
    public function a_volunteer_owed_nothing_is_still_skipped_after_the_run_stopped(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $this->waivedLine(PersonFactory::createOne(['organization' => $organization]), '2025-02-10');
        $hoursOnly = PersonFactory::createOne(['organization' => $organization, 'lastName' => 'Heures']);
        $declaration = DeclarationFactory::new()->forPerson($hoursOnly)->confirmed()->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->validated()->create([
            'date' => new DateTimeImmutable('2025-06-21'),
            'workHours' => '7.00',
            'journeys' => 0,
            'distanceKm' => 0,
            'ownVehicle' => false,
            'fiscalPower' => null,
        ]);

        $report = $this->runWhoseRendererFailsAfter(0)->run($organization, 2025);

        self::assertSame(['Heures'], $this->lastNames($report->skipped()));
        self::assertSame([], $report->notAttempted());
    }

    /**
     * Re-running a year issues a **new** receipt with a new number and leaves the first one
     * standing — that is the rectificatif, and the object key carries the number so the
     * earlier PDF survives too.
     */
    #[Test]
    public function running_a_year_twice_issues_a_second_receipt(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);
        $this->waivedLine($person, '2025-06-21');

        $first = $this->firstIssued($this->run->run($organization, 2025));
        $second = $this->firstIssued($this->run->run($organization, 2025));

        self::assertSame('0001', $first->getNumber());
        self::assertSame('0002', $second->getNumber());
        self::assertNotSame($first->getStoragePath(), $second->getStoragePath());
        self::assertCount(2, $this->entityManager->getRepository(Receipt::class)->findAll());
    }

    /**
     * DeclarationAction is not TenantAware, so nothing but the run's own query stands
     * between one association's receipts and another's contributions.
     */
    #[Test]
    public function another_associations_contributions_are_never_receipted(): void
    {
        $mine = $this->association();
        $theirs = $this->association();
        $this->exercice($mine, 2025);
        $this->exercice($theirs, 2025);

        $theirVolunteer = PersonFactory::createOne(['organization' => $theirs]);
        $this->waivedLine($theirVolunteer, '2025-06-21');

        $report = $this->run->run($mine, 2025);

        self::assertTrue($report->hasNothingToDo());
    }

    #[Test]
    public function the_object_key_carries_the_year_and_the_number(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne([
            'organization' => $organization,
            'firstName' => 'Camille',
            'lastName' => 'Berthier',
        ]);
        $this->waivedLine($person, '2025-06-21');

        $receipt = $this->firstIssued($this->run->run($organization, 2025));

        self::assertSame('2025/cerfa-camille-berthier-0001.pdf', $receipt->getStoragePath());
    }

    /**
     * The receipt of the first volunteer a run issued for, with its existence asserted.
     */
    private function firstIssued(ReceiptRunReport $report): Receipt
    {
        $issued = $report->issued();
        self::assertArrayHasKey(0, $issued);

        return $issued[0]->receipt();
    }

    /**
     * The real run, over a Gotenberg that answers $successes times and then is down.
     *
     * The kernel is booted afresh first: the run setUp() fetched already holds the real
     * client. Everything else is exactly what production gets.
     */
    private function runWhoseRendererFailsAfter(int $successes): YearlyReceiptRun
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        self::gotenbergFailsAfter($successes);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return self::getContainer()->get(YearlyReceiptRun::class);
    }

    /**
     * @param list<ReceiptRunOutcome> $outcomes
     *
     * @return list<string>
     */
    private function lastNames(array $outcomes): array
    {
        return array_map(static fn (ReceiptRunOutcome $outcome): string => $outcome->person->getLastName(), $outcomes);
    }

    private function association(): Organization
    {
        return OrganizationFactory::new()->withCerfaIdentity()->withSignature()->create();
    }

    private function exercice(Organization $organization, int $year): void
    {
        FiscalYearFactory::new()->for($organization)->calendarYear($year)
            ->withPublishedBareme()->create();
    }

    /**
     * One validated line with waived travel: 2 journeys of 34 km in the volunteer's own
     * vehicle, which at the 5 CV rate of 0,636 is 43,25 €.
     */
    private function waivedLine(Person $person, string $date): void
    {
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
