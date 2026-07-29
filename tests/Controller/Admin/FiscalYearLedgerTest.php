<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function substr_count;

/**
 * The two accounting pages of an exercice.
 *
 * `/ledger` is the écriture the association books — one centralising entry per family,
 * dated on the close. `/ledger/detail` is the per-volunteer breakdown that justifies an
 * individual reçu fiscal.
 *
 * Whether a line gets *in* — validated, and inside the period — is asserted on the detail
 * page, because that is the only one that names lines at all. What stays *out* is asserted
 * on both: DeclarationAction is deliberately not TenantAware, so nothing scopes those
 * queries except the code under test.
 */
final class FiscalYearLedgerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_detail_lists_a_validated_line_of_the_exercice(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-06-15', validated: true, title: 'Régate du printemps');
        $this->loginAdminOf($organization);

        $text = $this->detailText($fiscalYear);

        self::assertStringContainsString('Régate du printemps', $text);
        // The corrected pair: 875, not the 870 of the superseded règlement 99-01.
        self::assertStringContainsString('864', $text);
        self::assertStringContainsString('875', $text);
    }

    #[Test]
    public function it_excludes_a_line_that_is_not_validated(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-06-15', validated: false, title: 'Pas encore statué');
        $this->loginAdminOf($organization);

        // In the period, but nobody has ruled on it, so it is not bookable.
        self::assertStringNotContainsString('Pas encore statué', $this->detailText($fiscalYear));
    }

    #[Test]
    public function it_excludes_a_validated_line_outside_the_exercice(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2024-06-15', validated: true, title: 'Exercice précédent');
        $this->loginAdminOf($organization);

        self::assertStringNotContainsString('Exercice précédent', $this->detailText($fiscalYear));
    }

    /**
     * DeclarationAction has no organization column and is not covered by
     * OrganizationFilter, so this is the one thing between one association's ledger and
     * another's contributions.
     */
    #[Test]
    public function it_never_shows_another_associations_contributions(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        $fiscalYear = $this->year2025($mine);
        $this->lineOn($mine, '2025-06-15', validated: true, title: 'Mon chantier');
        $this->lineOn($theirs, '2025-06-15', validated: true, title: 'Leur secret');
        $this->loginAdminOf($mine);

        $text = $this->detailText($fiscalYear);

        self::assertStringContainsString('Mon chantier', $text);
        self::assertStringNotContainsString('Leur secret', $text);
    }

    #[Test]
    public function another_associations_ledger_is_not_reachable_by_url(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        $theirYear = $this->year2025($theirs);
        $this->loginAdminOf($mine);

        // The test and the controller share one EntityManager, so $theirYear is already
        // in the identity map and find() would hand it back without ever running the
        // filtered SQL — the isolation would look broken when it is not. A real request
        // always gets a fresh manager. Documented in AGENTS.md.
        $id = $theirYear->getId();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', '/admin/fiscal-year/'.$id->toRfc4122().'/ledger');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Waived travel produces the whole cycle, not a single credit: art. 141-4 requires
     * the volunteer's tiers account to be what the donation extinguishes.
     */
    #[Test]
    public function waived_travel_books_the_full_cycle(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()->create();
        $this->lineOn($organization, '2025-06-15', validated: true, title: 'Convoyage', attributes: [
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
            'journeys' => 2,
            'distanceKm' => 34,
        ]);
        $this->loginAdminOf($organization);

        $text = $this->ledgerText($fiscalYear);

        self::assertStringContainsString('6251', $text);
        self::assertStringContainsString('4681', $text);
        self::assertStringContainsString('75412', $text);
        // 68 km at the 5 CV rate of 0,636 = 43,248 € → 43,25 €.
        self::assertStringContainsString('43,25', $text);
    }

    /**
     * The summary is what a treasurer copies into a journal, so it has to be ONE entry per
     * family whatever the number of volunteers behind it.
     */
    #[Test]
    public function the_summary_books_one_entry_per_family_for_the_whole_exercice(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()->create();

        // Two volunteers, two hours each at the default 12,00 €/h, and travel for one.
        $this->lineOn($organization, '2025-03-15', validated: true, title: 'Chantier', attributes: ['workHours' => '2.00']);
        $this->lineOn($organization, '2025-06-15', validated: true, title: 'Convoyage', attributes: [
            'workHours' => '2.00',
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
            'journeys' => 2,
            'distanceKm' => 34,
        ]);
        $this->loginAdminOf($organization);

        $text = $this->ledgerText($fiscalYear);

        // 4 h at 12,00 € aggregated into a single 864 line, not two.
        self::assertSame(1, substr_count($text, 'Bénévolat valorisé de l\'exercice'));
        self::assertStringContainsString('48,00', $text);
        // Dated on the close, because that is when the écriture is passed.
        self::assertStringContainsString('31/12/2025', $text);
        // The volume behind the figures, so the amounts can be checked against something.
        self::assertStringContainsString('2 bénévoles', $text);
        self::assertStringContainsString('4,00', $text);
        self::assertStringContainsString('68 km', $text);
    }

    /**
     * Nothing on the page adds the two families together — the figure that would overstate
     * what a volunteer may deduct. 48,00 + 43,25 = 91,25, which must appear nowhere.
     */
    #[Test]
    public function the_summary_never_totals_the_two_families_together(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = FiscalYearFactory::new()->for($organization)->calendarYear(2025)
            ->withPublishedBareme()->create();
        $this->lineOn($organization, '2025-06-15', validated: true, title: 'Convoyage', attributes: [
            'workHours' => '4.00',
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
            'journeys' => 2,
            'distanceKm' => 34,
        ]);
        $this->loginAdminOf($organization);

        $text = $this->ledgerText($fiscalYear);

        self::assertStringContainsString('48,00', $text);
        self::assertStringContainsString('43,25', $text);
        self::assertStringNotContainsString('91,25', $text);
    }

    #[Test]
    public function another_associations_detail_is_not_reachable_by_url(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        $theirYear = $this->year2025($theirs);
        $this->loginAdminOf($mine);

        // Same identity-map caveat as the summary test above.
        $id = $theirYear->getId();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', '/admin/fiscal-year/'.$id->toRfc4122().'/ledger/detail');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function an_exercice_with_nothing_validated_says_so(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->year2025($organization);
        $this->loginAdminOf($organization);

        self::assertStringContainsString('Aucune action validée', $this->ledgerText($fiscalYear));
    }

    private function year2025(Organization $organization): FiscalYear
    {
        return FiscalYearFactory::new()->for($organization)->calendarYear(2025)->create();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function lineOn(
        Organization $organization,
        string $date,
        bool $validated,
        string $title,
        array $attributes = [],
    ): void {
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();
        $factory = DeclarationActionFactory::new()->forDeclaration($declaration);
        $factory = $validated ? $factory->validated() : $factory->confirmed();

        $factory->create([...$attributes, 'date' => new DateTimeImmutable($date), 'title' => $title]);
    }

    private function loginAdminOf(Organization $organization): void
    {
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());
    }

    private function ledgerUrl(FiscalYear $fiscalYear): string
    {
        return '/admin/fiscal-year/'.$fiscalYear->getId()->toRfc4122().'/ledger';
    }

    private function ledgerText(FiscalYear $fiscalYear): string
    {
        return $this->textOf($this->ledgerUrl($fiscalYear));
    }

    private function detailText(FiscalYear $fiscalYear): string
    {
        return $this->textOf($this->ledgerUrl($fiscalYear).'/detail');
    }

    private function textOf(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler()->filter('body')->text();
    }
}
