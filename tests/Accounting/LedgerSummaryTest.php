<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\LedgerBuilder;
use App\Accounting\LedgerSummary;
use App\Accounting\PcgAccount;
use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;
use function sprintf;

/**
 * The centralising écriture of an exercice.
 *
 * The page it feeds is what a treasurer copies into a journal, so the properties that
 * matter are arithmetic ones: one entry per family whatever the number of volunteers, each
 * account balanced, and the two families never merged.
 */
final class LedgerSummaryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function it_aggregates_every_volunteer_into_one_entry_per_family(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = $this->year2025($organization);

        // Three volunteers, 2 h each at the default 12,00 €/h.
        foreach (['2025-02-01', '2025-05-01', '2025-09-01'] as $date) {
            $this->lineOn($organization, $date, ['workHours' => '2.00']);
        }

        $summary = $this->summaryOf($fiscalYear);

        self::assertSame(3, $summary->volunteerCount);
        self::assertSame(600, $summary->workHoursInHundredths);
        self::assertSame(7200, $summary->hoursCents);
        self::assertSame(0, $summary->mileageCents);

        // Two lines, not six: nothing was waived, so the abandon de frais écriture does
        // not exist and must not appear as three zero rows.
        $entries = $summary->entries();
        self::assertCount(2, $entries);
        self::assertSame(PcgAccount::PERSONNEL_BENEVOLE, $entries[0]->account);
        self::assertSame(7200, $entries[0]->debitCents);
        self::assertSame(PcgAccount::BENEVOLAT, $entries[1]->account);
        self::assertSame(7200, $entries[1]->creditCents);
    }

    /**
     * Waived travel books the full cycle — art. 141-4 requires the volunteer's tiers
     * account to be what the donation extinguishes, so 4681 appears on both sides.
     */
    #[Test]
    public function waived_travel_books_six_lines_and_balances_every_account(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-06-21', [
            'workHours' => '4.00',
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
            'journeys' => 2,
            'distanceKm' => 34,
        ]);

        $summary = $this->summaryOf($fiscalYear);

        // 68 km at the 5 CV rate of 0,636 = 43,248 € → 43,25 €.
        self::assertSame(4325, $summary->mileageCents);
        self::assertSame(68, $summary->waivedDistanceKm);
        self::assertCount(6, $summary->entries());

        // 4681 carries both sides — the debt, then its extinction — and they cancel out.
        self::assertSame([4325, 4325], $this->balanceOf($summary, PcgAccount::FRAIS_DES_BENEVOLES));

        // Every other account carries one side only, and each pair is equal.
        self::assertSame([4800, 0], $this->balanceOf($summary, PcgAccount::PERSONNEL_BENEVOLE));
        self::assertSame([0, 4800], $this->balanceOf($summary, PcgAccount::BENEVOLAT));
        self::assertSame([4325, 0], $this->balanceOf($summary, PcgAccount::VOYAGES_ET_DEPLACEMENTS));
        self::assertSame([0, 4325], $this->balanceOf($summary, PcgAccount::ABANDONS_DE_FRAIS));
    }

    /**
     * Every line is dated on the exercice's close: the movements happened all year, the
     * écriture recording them is passed at closing.
     */
    #[Test]
    public function every_line_is_dated_on_the_close_of_the_exercice(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-03-15', ['workHours' => '1.00']);

        foreach ($this->summaryOf($fiscalYear)->entries() as $entry) {
            self::assertSame('2025-12-31', $entry->date->format('Y-m-d'));
        }
    }

    /**
     * The split the whole page rests on: classe 8 on one side, the real flow on the other,
     * and no line belonging to both.
     */
    #[Test]
    public function the_two_families_are_kept_apart(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-06-21', [
            'workHours' => '4.00',
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
            'journeys' => 2,
            'distanceKm' => 34,
        ]);

        $summary = $this->summaryOf($fiscalYear);

        self::assertCount(2, $summary->offBalanceSheetEntries());
        self::assertCount(4, $summary->realFlowEntries());
        self::assertSame(
            count($summary->entries()),
            count($summary->offBalanceSheetEntries()) + count($summary->realFlowEntries()),
        );
    }

    /**
     * Kilometres travelled as a passenger waive nothing, so they must not be counted next
     * to an amount they cannot be reconciled with.
     */
    #[Test]
    public function kilometres_that_waive_nothing_are_not_counted(): void
    {
        $organization = OrganizationFactory::createOne();
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-06-21', [
            'workHours' => '1.00',
            'ownVehicle' => false,
            'fiscalPower' => null,
            'journeys' => 2,
            'distanceKm' => 40,
        ]);

        $summary = $this->summaryOf($fiscalYear);

        self::assertSame(0, $summary->waivedDistanceKm);
        self::assertSame(0, $summary->mileageCents);
    }

    #[Test]
    public function an_exercice_with_nothing_validated_is_empty(): void
    {
        $summary = $this->summaryOf($this->year2025(OrganizationFactory::createOne()));

        self::assertTrue($summary->isEmpty());
        self::assertSame([], $summary->entries());
        self::assertSame([], $summary->balances());
    }

    /**
     * An account's [débit, crédit], with the account's presence asserted rather than
     * assumed — a missing key would otherwise read as a zero balance.
     *
     * @return array{int, int}
     */
    private function balanceOf(LedgerSummary $summary, PcgAccount $account): array
    {
        foreach ($summary->balances() as $balance) {
            if ($balance->account === $account) {
                return [$balance->debitCents, $balance->creditCents];
            }
        }

        self::fail(sprintf('No balance for account %s.', $account->value));
    }

    private function year2025(Organization $organization): FiscalYear
    {
        return FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()->create();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function lineOn(Organization $organization, string $date, array $attributes): void
    {
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();

        DeclarationActionFactory::new()
            ->forDeclaration($declaration)
            ->validated()
            ->create([...$attributes, 'date' => new DateTimeImmutable($date)]);
    }

    private function summaryOf(FiscalYear $fiscalYear): LedgerSummary
    {
        return self::getContainer()->get(LedgerBuilder::class)->build($fiscalYear)->summary();
    }
}
