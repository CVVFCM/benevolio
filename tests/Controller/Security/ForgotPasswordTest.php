<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Tests\Support\ResetsRateLimiters;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;

/**
 * « Mot de passe oublié » — and that it does not tell anyone who has an account.
 */
final class ForgotPasswordTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use ResetsRateLimiters;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetRateLimiters();
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

    /**
     * One address is sent three reset mails an hour, no more — and past that the
     * answer is the same page, so the limit says nothing about who has an account.
     */
    #[Test]
    public function an_address_is_mailed_three_times_an_hour_and_then_gets_the_same_page_without_a_mail(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'tresorier@jardins.test']);

        $sent = [];
        $pages = [];
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->submit('tresorier@jardins.test');
            self::assertResponseIsSuccessful();
            // The mailer's log is per request: one mail, or none.
            $sent[] = count(self::getMailerMessages());
            $pages[] = $this->client->getCrawler()->filter('main')->text();
        }

        self::assertSame([1, 1, 1, 0], $sent);
        self::assertCount(1, array_unique($pages), 'The page is the same whether or not a mail went out.');
    }

    /**
     * The limit is per address: someone hammering one account does not lock
     * everybody else out of resetting theirs.
     */
    #[Test]
    public function the_limit_on_one_address_does_not_touch_another(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->admin($organization)->create(['email' => 'tresorier@jardins.test']);
        UserFactory::new()->admin($organization)->create(['email' => 'secretaire@jardins.test']);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->submit('tresorier@jardins.test');
        }
        self::assertEmailCount(0);

        $this->submit('secretaire@jardins.test');

        self::assertEmailCount(1);
    }

    private function submit(string $email): void
    {
        $this->client->request(Request::METHOD_GET, '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir un lien', ['forgot_password[email]' => $email]);
    }
}
