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
use App\Receipt\ReceiptRunReport;
use App\Receipt\YearlyReceiptRun;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

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
     * A civil year straddling two exercices is priced by the FIRST one, for the whole year.
     *
     * This is the rule that makes a receipt defensible: one rate set per civil year, not one per
     * contribution. Otherwise a volunteer's January kilometres and their November kilometres
     * would be worth different amounts on the same document, decided by where the association
     * happens to close its books.
     */
    #[Test]
    public function a_year_straddling_two_exercices_is_priced_by_the_first(): void
    {
        $organization = $this->association();
        $person = PersonFactory::createOne(['organization' => $organization]);

        // September-to-August exercices, carrying different rates so the amount says which one
        // priced the year. Civil 2026 is intersected by both; 2025-2026 comes first.
        FiscalYearFactory::new()->for($organization)->closed()->create([
            'name' => '2025-2026',
            'beginsOn' => new DateTimeImmutable('2025-09-01'),
            'endsOn' => new DateTimeImmutable('2026-08-31'),
            'defaultMilliEurosPerKm' => 500,
        ]);
        FiscalYearFactory::new()->for($organization)->closed()->create([
            'name' => '2026-2027',
            'beginsOn' => new DateTimeImmutable('2026-09-01'),
            'endsOn' => new DateTimeImmutable('2027-08-31'),
            'defaultMilliEurosPerKm' => 700,
        ]);

        // One line either side of the closing date, 68 km each. Both priced at 0,500 — the
        // 2025-2026 rate — for 34,00 € twice, and NOT 34,00 + 47,60.
        $this->waivedLine($person, '2026-03-15');
        $this->waivedLine($person, '2026-10-15');

        $report = $this->run->run($organization, 2026);

        self::assertSame(1, $report->issuedCount());
        self::assertSame(3400 + 3400, $report->totalCents());
    }

    /**
     * The exercice pricing the year must be CLOSED, or nothing is issued.
     *
     * An open exercice still has editable rates, so a receipt built from it could be
     * contradicted the next day. Refused for the whole batch, like a missing SIREN — a condition
     * a human has to lift.
     */
    #[Test]
    public function an_open_exercice_refuses_the_whole_run(): void
    {
        $organization = $this->association();
        // Deliberately NOT ->closed().
        FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()->create();
        $person = PersonFactory::createOne(['organization' => $organization]);
        $this->waivedLine($person, '2025-06-21');

        $report = $this->run->run($organization, 2025);

        self::assertTrue($report->isRefused());
        self::assertStringContainsString('clôturé', $report->refusalReason());
        self::assertCount(0, $this->entityManager->getRepository(Receipt::class)->findAll());
    }

    #[Test]
    public function a_year_with_no_exercice_at_all_refuses_the_run(): void
    {
        $organization = $this->association();
        $this->exercice($organization, 2025);
        $person = PersonFactory::createOne(['organization' => $organization]);
        $this->waivedLine($person, '2025-06-21');

        // 2026 has no exercice: no barème, so no figure to state.
        $report = $this->run->run($organization, 2026);

        self::assertTrue($report->isRefused());
        self::assertStringContainsString('Aucun exercice', $report->refusalReason());
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

    private function association(): Organization
    {
        return OrganizationFactory::new()->withCerfaIdentity()->withSignature()->create();
    }

    private function exercice(Organization $organization, int $year): void
    {
        FiscalYearFactory::new()->for($organization)->calendarYear($year)
            ->withPublishedBareme()->closed()->create();
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
