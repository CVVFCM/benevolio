<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The whole way in for an account created without a password: the emailed link,
 * the page that will not let go until a password is chosen, and the link dying
 * once it has done its job.
 */
final class LoginLinkFlowTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string NEW_PASSWORD = 'un mot de passe neuf';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function a_login_link_logs_in_and_lands_on_choose_password(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();

        $this->client->request(Request::METHOD_GET, $this->linkFor($user));

        self::assertResponseRedirects('/mot-de-passe');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Choisissez votre mot de passe');
    }

    /**
     * Without this an invited admin could work from the link's session and find
     * next time that they cannot log in.
     */
    #[Test]
    public function the_back_office_is_out_of_reach_until_a_password_is_chosen(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();
        $this->client->request(Request::METHOD_GET, $this->linkFor($user));

        $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseRedirects('/mot-de-passe');

        $this->client->request(Request::METHOD_GET, '/admin/declaration');
        self::assertResponseRedirects('/mot-de-passe');
    }

    #[Test]
    public function choosing_a_password_keeps_the_user_logged_in_and_lets_them_log_in_with_it(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();
        $this->client->request(Request::METHOD_GET, $this->linkFor($user));

        $this->choosePassword(self::NEW_PASSWORD);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Votre mot de passe est enregistré.');

        $reloaded = $this->reload($user);
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($reloaded, self::NEW_PASSWORD),
        );

        // Still logged in on the next page, despite the hash having changed.
        $this->client->request(Request::METHOD_GET, '/admin/declaration');
        self::assertResponseIsSuccessful();
    }

    /**
     * Signed with the password hash: setting one kills every link issued before.
     */
    #[Test]
    public function a_link_no_longer_works_once_the_password_is_set(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();
        $link = $this->linkFor($user);

        $this->client->request(Request::METHOD_GET, $link);
        $this->choosePassword(self::NEW_PASSWORD);
        $this->client->request(Request::METHOD_GET, '/logout');

        $this->client->request(Request::METHOD_GET, $link);

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function an_expired_link_does_not_log_in(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();

        $this->client->request(Request::METHOD_GET, $this->linkFor($user, lifetime: -60));

        self::assertResponseRedirects('/login');
        $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseRedirects('/login');
    }

    /**
     * A forgotten-password link logs in an account that HAS a password, which
     * only the session flag tells apart from an ordinary login.
     */
    #[Test]
    public function a_reset_link_forces_the_choice_even_when_a_password_exists(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->create();

        $this->client->request(Request::METHOD_GET, $this->linkFor($user));
        $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseRedirects('/mot-de-passe');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.login__text', 'L\'ancien ne fonctionnera plus');
    }

    #[Test]
    public function an_ordinary_password_login_is_not_sent_to_choose_password(): void
    {
        $this->client->loginUser(UserFactory::new()->admin(OrganizationFactory::createOne())->create());

        $this->client->request(Request::METHOD_GET, '/admin/declaration');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function mismatched_passwords_are_refused_with_422(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();
        $this->client->request(Request::METHOD_GET, $this->linkFor($user));

        $this->client->request(Request::METHOD_GET, '/mot-de-passe');
        $this->client->submitForm('Enregistrer mon mot de passe', [
            'choose_password[plainPassword][first]' => self::NEW_PASSWORD,
            'choose_password[plainPassword][second]' => 'autre chose entièrement',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->reload($user)->hasPassword());
    }

    #[Test]
    public function a_too_short_password_is_refused(): void
    {
        $user = UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create();
        $this->client->request(Request::METHOD_GET, $this->linkFor($user));

        $this->choosePassword('court');

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->reload($user)->hasPassword());
    }

    #[Test]
    public function a_super_admin_lands_on_the_platform(): void
    {
        $user = UserFactory::new()->superAdmin()->invited()->create();
        $this->client->request(Request::METHOD_GET, $this->linkFor($user));

        $this->choosePassword(self::NEW_PASSWORD);

        self::assertResponseRedirects('/platform');
    }

    #[Test]
    public function the_page_needs_a_login(): void
    {
        $this->client->request(Request::METHOD_GET, '/mot-de-passe');

        self::assertResponseRedirects('/login');
    }

    private function choosePassword(string $password): void
    {
        $this->client->request(Request::METHOD_GET, '/mot-de-passe');
        $this->client->submitForm('Enregistrer mon mot de passe', [
            'choose_password[plainPassword][first]' => $password,
            'choose_password[plainPassword][second]' => $password,
        ]);
    }

    private function linkFor(User $user, ?int $lifetime = null): string
    {
        // The main firewall's handler, as LoginLinkMailer uses: the firewall-aware
        // alias needs a current request to pick one.
        $handler = self::getContainer()->get('security.authenticator.login_link_handler.main');

        return $handler->createLoginLink($this->reload($user), null, $lifetime)
            ->getUrl();
    }

    private function reload(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloaded = $entityManager->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
