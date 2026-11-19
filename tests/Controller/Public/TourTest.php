<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Organization;
use App\Entity\Person;
use App\Factory\ActivityFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use App\ValueObject\Address;
use App\ValueObject\Email;
use App\Volunteer\VolunteerLink;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function array_map;
use function count;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The guided tours of the public pages (#50): one per form step and one on « Mes
 * déclarations », each pointing at elements the page really has, replayed by « Aide », and
 * opening by itself only where it should. What happens in the browser is driver.js's; what
 * is pinned here is what the page hands it.
 */
final class TourTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use ResetsRateLimiters;
    use WalksDeclarationFlow;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetRateLimiters();
    }

    #[Test]
    public function each_step_of_the_form_has_its_own_tour_opening_by_itself(): void
    {
        OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        // Anonymous: the space's tabs are not there, and their step is left out.
        $this->assertTour($crawler, 'declaration-person', true, ['space']);

        $crawler = $this->client->submitForm('Suivant', $this->personStep());
        // An association with no activity renders no activity select.
        $this->assertTour($crawler, 'declaration-actions', true, ['activity']);

        // A fresh session: the walker starts from the first step, which this one has left.
        $this->client->restart();
        $this->reachLegalStep();
        $this->assertTour($this->client->getCrawler(), 'declaration-legal', true, []);
    }

    #[Test]
    public function the_actions_tour_points_at_the_activity_select_when_there_is_one(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        ActivityFactory::new()->for($organization)->create();
        $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        $crawler = $this->client->submitForm('Suivant', $this->personStep());

        $this->assertTour($crawler, 'declaration-actions', true, []);
    }

    #[Test]
    public function a_step_redisplayed_with_its_errors_does_not_open_its_tour(): void
    {
        OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        $crawler = $this->client->submitForm('Suivant', $this->personStep(['email' => '']));

        self::assertSelectorExists('.error-summary');
        $this->assertTour($crawler, 'declaration-person', false, ['space']);
    }

    #[Test]
    public function the_form_does_not_open_its_tour_for_a_logged_in_volunteer(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $this->loginVolunteer($organization);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        // Logged in: no « Se connecter », the tabs instead.
        $this->assertTour($crawler, 'declaration-person', false, ['login']);
    }

    #[Test]
    public function my_declarations_opens_its_tour_by_itself(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $this->loginVolunteer($organization);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');

        // Only logged-in volunteers see this page, so the rule for the form does not apply.
        $this->assertTour($crawler, 'volunteer-declarations', true, []);
    }

    #[Test]
    public function a_page_without_a_tour_has_no_help_button(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $this->loginVolunteer($organization);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-recus');

        self::assertCount(0, $crawler->filter('[data-controller~="tour"]'));
        self::assertCount(0, $crawler->filter('[data-tour-step="help"]'));
    }

    /**
     * The page carries the tour controller with this id and autostart, a hidden « Aide »
     * button replaying it, and steps whose element is on the page — except those named in
     * $absent, which the controller leaves out on this page.
     *
     * @param list<string> $absent the data-tour-step names expected missing
     */
    private function assertTour(Crawler $crawler, string $id, bool $autostart, array $absent): void
    {
        $tour = $crawler->filter('[data-controller~="tour"]');
        self::assertCount(1, $tour);
        self::assertSame($id, $tour->attr('data-tour-id-value'));
        self::assertSame($autostart ? 'true' : 'false', $tour->attr('data-tour-autostart-value'));

        $help = $tour->filter('.public__account [data-tour-step="help"][data-action="tour#start"]');
        self::assertCount(1, $help);
        self::assertSame('Aide', $help->text());
        self::assertNotNull($help->attr('hidden'), 'Without JavaScript the button would do nothing.');

        $steps = json_decode((string) $tour->attr('data-tour-steps-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($steps);
        self::assertGreaterThan(0, count($steps));

        $missing = [];
        foreach ($steps as $step) {
            self::assertIsArray($step);
            self::assertTrue(is_string($step['title'] ?? null) && is_string($step['description'] ?? null));
            $selector = $step['element'] ?? null;
            self::assertIsString($selector);

            if (0 === $crawler->filter($selector)->count()) {
                $missing[] = $selector;
            }
        }

        self::assertSame(
            array_map(static fn (string $name): string => '[data-tour-step="'.$name.'"]', $absent),
            $missing,
        );
    }

    private function loginVolunteer(Organization $organization): Person
    {
        $person = PersonFactory::new()->for($organization)->create([
            'email' => new Email('connu@example.test'),
            'address' => new Address('3', 'rue des Jardins', '44000', 'Nantes', 'FR'),
        ]);
        DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        $link = self::getContainer()->get(VolunteerLink::class)->create(
            $organization,
            $person->getEmail(),
            $person->getLoginLinkVersion(),
            new DateTimeImmutable('+1 hour'),
        );
        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseRedirects();

        return $person;
    }
}
