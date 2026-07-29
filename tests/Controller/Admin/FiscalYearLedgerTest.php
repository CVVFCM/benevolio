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

/**
 * The draft ledger page.
 *
 * Two things are worth holding here. What gets *in* — validated lines of this exercice
 * and nothing else — and what stays *out*, because DeclarationAction is deliberately not
 * TenantAware, so nothing scopes this query except the code under test.
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
    public function it_lists_a_validated_line_of_the_exercice(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2025-06-15', validated: true, title: 'Régate du printemps');
        $this->loginAdminOf($organization);

        $text = $this->ledgerText($fiscalYear);

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
        self::assertStringNotContainsString('Pas encore statué', $this->ledgerText($fiscalYear));
    }

    #[Test]
    public function it_excludes_a_validated_line_outside_the_exercice(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->year2025($organization);
        $this->lineOn($organization, '2024-06-15', validated: true, title: 'Exercice précédent');
        $this->loginAdminOf($organization);

        self::assertStringNotContainsString('Exercice précédent', $this->ledgerText($fiscalYear));
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

        $text = $this->ledgerText($fiscalYear);

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
        $this->client->request('GET', $this->ledgerUrl($fiscalYear));
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler()->filter('body')->text();
    }
}
