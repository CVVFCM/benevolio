<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\OrganizationFactory;
use App\Tests\Support\LogsInAdmins;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function sprintf;

/**
 * The public home page: anonymous, cacheable, and naming only the associations that
 * asked to be named.
 */
final class HomeTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_is_public_and_cacheable(): void
    {
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le bénévolat de votre association');
        // Nothing woke the lazy firewall: no session, and a response a CDN may keep.
        self::assertResponseHeaderSame('Cache-Control', 'max-age=300, public');
        self::assertResponseNotHasCookie('MOCKSESSID');
    }

    #[Test]
    public function it_lists_the_associations_that_opted_in_with_a_link_to_their_form(): void
    {
        OrganizationFactory::createOne(['name' => 'Les Jardins', 'slug' => 'jardins', 'listedOnHomepage' => true]);
        OrganizationFactory::createOne(['name' => 'Club Discret', 'slug' => 'discret']);
        OrganizationFactory::new()->inactive()->create(['name' => 'Club Fermé', 'slug' => 'ferme', 'listedOnHomepage' => true]);

        $crawler = $this->client->request(Request::METHOD_GET, '/');

        $names = $crawler->filter('.tenant__name')->each(static fn ($node): string => $node->text());
        self::assertSame(['Les Jardins'], $names);
        self::assertSelectorExists('a[href="/a/jardins/declaration"]');
    }

    #[Test]
    public function with_no_association_listed_the_section_is_left_out(): void
    {
        OrganizationFactory::createOne();

        $this->client->request(Request::METHOD_GET, '/');

        self::assertSelectorNotExists('#associations');
        self::assertSelectorNotExists('a[href="#associations"]');
    }

    #[Test]
    public function it_offers_the_platform_address_to_get_in_touch(): void
    {
        $this->client->request(Request::METHOD_GET, '/');

        $mailFrom = self::getContainer()->getParameter('app.mail_from');
        self::assertSelectorExists(sprintf('a[href^="mailto:%s?subject="]', $mailFrom));
    }

    #[Test]
    public function every_screenshot_it_shows_is_served(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/');
        $sources = $crawler->filter('img')->each(static fn ($node): string => (string) $node->attr('src'));

        self::assertCount(3, $sources);

        foreach ($sources as $source) {
            $this->client->request(Request::METHOD_GET, $source);
            self::assertResponseIsSuccessful($source);
        }
    }

    #[Test]
    public function the_back_office_link_sends_each_account_where_it_belongs(): void
    {
        $this->client->request(Request::METHOD_GET, '/espace');
        self::assertResponseRedirects('http://localhost/login');

        $this->loginAsSuperAdmin();
        $this->client->request(Request::METHOD_GET, '/espace');
        self::assertResponseRedirects('/platform');

        $this->loginAsAdminOf(OrganizationFactory::createOne());
        $this->client->request(Request::METHOD_GET, '/espace');
        self::assertResponseRedirects('/admin');
    }
}
