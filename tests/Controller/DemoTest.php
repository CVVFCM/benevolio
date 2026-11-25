<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Demo\DemoAssociation;
use App\Demo\DemoReset;
use App\Entity\Declaration;
use App\Entity\Organization;
use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The public demo (#62), as a visitor meets it: the page with its login and its warning, and
 * an association that sends no mail — so its declarations confirm as they are filed, and its
 * volunteer space, which logs in by mail, is closed.
 */
final class DemoTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReloadsEntities;
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
    public function the_page_gives_the_login_and_warns_that_everything_is_shared(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/demo');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=3600, public');
        self::assertResponseNotHasCookie('MOCKSESSID');
        self::assertSelectorTextContains('.demo__warning', 'N\'y saisissez aucune donnée réelle.');
        self::assertSelectorTextContains('.demo__warning', 'visible par tous');
        self::assertSelectorTextContains('.demo__login', DemoAssociation::ADMIN_EMAIL);
        self::assertSelectorTextContains('.demo__login', DemoAssociation::ADMIN_PASSWORD);
        self::assertCount(1, $crawler->filter('a[href="/login"]'));
        self::assertCount(1, $crawler->filter('a[href="/a/demo/declaration"]'));
        // Indexable, by decision.
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
    }

    #[Test]
    public function the_site_links_to_it_and_lists_it_for_crawlers(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/');
        self::assertGreaterThan(0, $crawler->filter('a[href="/demo"]')->count());

        $this->client->request(Request::METHOD_GET, '/sitemap.xml');
        self::assertStringContainsString('http://localhost/demo', (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/llms.txt');
        self::assertStringContainsString('http://localhost/demo', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function a_declaration_filed_on_the_demo_is_confirmed_at_once_and_mails_nobody(): void
    {
        $this->demo();

        $this->completeFlow(slug: 'demo');

        self::assertResponseRedirects('/a/demo/declaration/merci');
        self::assertEmailCount(0);

        $declaration = $this->findOne(Declaration::class, ['submittedLastName' => 'Dupont']);
        self::assertTrue($declaration->isConfirmed());
        self::assertNull($declaration->getConfirmedBy());
        foreach ($declaration->getActions() as $action) {
            self::assertFalse($action->getState()->isAwaitingConfirmation());
        }

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Déclaration enregistrée');
        self::assertSelectorTextContains('.done__note', 'aucun courriel');
    }

    #[Test]
    public function the_volunteer_space_is_closed_on_the_demo(): void
    {
        $this->demo();

        $crawler = $this->client->request(Request::METHOD_GET, '/a/demo/declaration');
        self::assertCount(0, $crawler->filter('a[href="/a/demo/connexion"]'));

        $this->client->request(Request::METHOD_GET, '/a/demo/connexion');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pas d\'espace bénévole dans la démo');
        self::assertCount(0, $this->client->getCrawler()->filter('input[type="email"]'));
    }

    /**
     * Its login is public: an invitation would mail any address a visitor typed.
     */
    #[Test]
    public function inviting_an_admin_on_the_demo_mails_nobody(): void
    {
        $this->demo();
        $this->loginAs($this->findOne(User::class, ['email' => DemoAssociation::ADMIN_EMAIL]));

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/user/new');
        $this->client->submit($crawler->selectButton('Créer')->form(['User[email]' => 'victime@example.test']));

        self::assertResponseRedirects();
        self::assertEmailCount(0);
    }

    #[Test]
    public function a_forgotten_password_for_the_demo_login_mails_nobody(): void
    {
        $this->demo();

        $this->client->request(Request::METHOD_GET, '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir un lien', ['forgot_password[email]' => DemoAssociation::ADMIN_EMAIL]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    #[Test]
    public function other_associations_still_send_their_mail(): void
    {
        $this->demo();
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $this->completeFlow();

        self::assertEmailCount(1);
    }

    private function demo(): Organization
    {
        self::getContainer()->get(DemoReset::class)->reset();
        $this->entityManager()->clear();

        return $this->findOne(Organization::class, ['slug' => 'demo']);
    }
}
