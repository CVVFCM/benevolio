<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * « Mot de passe oublié » — and that it does not tell anyone who has an account.
 */
final class ForgotPasswordTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_login_page_links_to_it(): void
    {
        $this->client->request(Request::METHOD_GET, '/login');

        self::assertSelectorExists('a[href="/mot-de-passe-oublie"]');
    }

    #[Test]
    public function a_known_address_receives_a_one_hour_login_link(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Les Jardins']);
        UserFactory::new()->admin($organization)->create(['email' => 'tresorier@jardins.test']);

        $this->submit('tresorier@jardins.test');

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertEmailAddressContains($email, 'To', 'tresorier@jardins.test');
        self::assertSame('Les Jardins', ($email->getFrom()[0] ?? null)?->getName());
        self::assertEmailTextBodyContains($email, '/login/lien?');
    }

    #[Test]
    public function an_unknown_address_gets_the_same_answer_and_no_mail(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'tresorier@jardins.test']);

        $this->submit('tresorier@jardins.test');
        $known = $this->client->getCrawler()->filter('main')->text();

        $this->submit('personne@nulle-part.test');
        $unknown = $this->client->getCrawler()->filter('main')->text();

        self::assertResponseIsSuccessful();
        self::assertSame($known, $unknown);
        self::assertEmailCount(0);
    }

    #[Test]
    public function a_platform_super_admin_can_use_it_too(): void
    {
        UserFactory::new()->superAdmin()->create(['email' => 'patron@plateforme.test']);

        $this->submit('patron@plateforme.test');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('Benevolio', ($email->getFrom()[0] ?? null)?->getName());
    }

    #[Test]
    public function an_invalid_address_is_refused_with_422(): void
    {
        $this->submit('pas une adresse');

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    private function submit(string $email): void
    {
        $this->client->request(Request::METHOD_GET, '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir un lien', ['forgot_password[email]' => $email]);
    }
}
