<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Declaration;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\UserFactory;
use App\ValueObject\Address;
use App\ValueObject\Email;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The page a treasurer rules from.
 *
 * It used to show the volunteer as one line of text and the declared lines as a
 * list of stringified links, so nothing that a verdict actually depends on was
 * visible. These assertions are about what is on the page, not how it looks.
 */
final class DeclarationDetailTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_shows_who_the_volunteer_is_and_where_they_live(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        // PersonFactory has no for(); the organization goes through with(), as
        // DeclarationFactory::defaults() does.
        $person = PersonFactory::new()->with(['organization' => $organization])->create([
            'firstName' => 'Camille',
            'lastName' => 'Berthier',
            'email' => new Email('camille.berthier@example.org'),
            'address' => new Address('12', 'rue des Tilleuls', '08000', 'Charleville-Mézières', 'FR'),
        ]);
        $declaration = DeclarationFactory::new()->forPerson($person)->create();
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());

        $text = $this->detailText($declaration);

        self::assertStringContainsString('Camille Berthier', $text);
        self::assertStringContainsString('camille.berthier@example.org', $text);
        // The readable line from Address itself, not the parts reassembled here.
        self::assertStringContainsString('12 rue des Tilleuls, 08000 Charleville-Mézières', $text);
    }

    #[Test]
    public function it_shows_each_declared_line_with_its_figures(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create([
            'title' => 'Régate du printemps',
            'workHours' => '6.50',
            'consecutiveDays' => 1,
            'journeys' => 2,
            'distanceKm' => 12,
        ]);
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());

        $text = $this->detailText($declaration);

        self::assertStringContainsString('Régate du printemps', $text);
        self::assertStringContainsString('6.50 h', $text);
        // Total distance, and the multiplication behind it.
        self::assertStringContainsString('24', $text);
        self::assertStringContainsString('12 × 2', $text);
        // In step with its declaration, which has not been confirmed here.
        self::assertStringContainsString('En attente de confirmation', $text);
    }

    /**
     * A declaration with no lines cannot reach a verdict, so the page has to say
     * something rather than render an empty table.
     */
    #[Test]
    public function it_says_so_when_there_are_no_lines(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = DeclarationFactory::new()->for($organization)->create();
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());

        self::assertStringContainsString('Aucune action déclarée', $this->detailText($declaration));
    }

    private function detailText(Declaration $declaration): string
    {
        $this->client->request('GET', '/admin/declaration/'.$declaration->getId()->toRfc4122());
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler()->filter('body')->text();
    }
}
