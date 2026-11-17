<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Security\Role;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\Tests\Support\ReloadsEntities;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * « Administrateurs »: an association managing its own back-office accounts.
 */
final class UserAdminTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReadsAdminPages;
    use ReloadsEntities;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function creating_an_account_takes_an_email_only_and_sends_an_invitation(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Les Jardins']);
        $this->loginAs(UserFactory::new()->owner($organization)->create());

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/user/new');
        self::assertSelectorNotExists('input[type="password"]');
        $form = $crawler->selectButton('Créer')->form(['User[email]' => 'nouvelle@jardins.test']);
        $this->client->submit($form);

        self::assertResponseRedirects();

        $user = $this->findOne(User::class, ['email' => 'nouvelle@jardins.test']);
        self::assertFalse($user->hasPassword());
        self::assertTrue($user->hasRole(Role::ADMIN));
        self::assertFalse($user->isOwner());
        self::assertSame($organization->getId()->toRfc4122(), $user->getOrganization()?->getId()->toRfc4122());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertEmailAddressContains($email, 'To', 'nouvelle@jardins.test');
        self::assertSame('Les Jardins', ($email->getFrom()[0] ?? null)?->getName());
        self::assertEmailTextBodyContains($email, '/login/lien?');
    }

    #[Test]
    public function the_list_shows_role_and_status(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create(['email' => 'chef@asso.test']);
        UserFactory::new()->admin($organization)->invited()->create(['email' => 'attente@asso.test']);
        $this->loginAs($owner);

        $this->client->request(Request::METHOD_GET, '/admin/user');

        self::assertResponseIsSuccessful();
        $row = static fn (KernelBrowser $client, string $email): string => $client->getCrawler()
            ->filter('tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), $email))->text();
        self::assertStringContainsString('Responsable de l\'association', $row($this->client, 'chef@asso.test'));
        self::assertStringContainsString('Actif', $row($this->client, 'chef@asso.test'));
        self::assertStringContainsString('Administrateur de l\'association', $row($this->client, 'attente@asso.test'));
        self::assertStringContainsString('Invitation en attente', $row($this->client, 'attente@asso.test'));
    }

    #[Test]
    public function an_invitation_can_be_resent_while_pending(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->admin($organization)->create());
        $pending = UserFactory::new()->admin($organization)->invited()->create(['email' => 'attente@asso.test']);

        $crawler = $this->client->request(Request::METHOD_GET, self::detailUrl('/admin/user', $pending->getId()));
        $this->client->submit($crawler->filter('form[action$="/resend-invitation"]')->form());

        self::assertResponseRedirects();
        self::assertEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invitation renvoyée à attente@asso.test');
    }

    /**
     * From the list too. EasyAdmin's actions dropdown renders its own form and ignores
     * the action's template, which carries the CSRF token: there, the button always
     * answered « La page a expiré ». The CRUD shows its actions inlined for that reason.
     */
    #[Test]
    public function an_invitation_can_be_resent_from_the_list(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->admin($organization)->create());
        UserFactory::new()->admin($organization)->invited()->create(['email' => 'attente@asso.test']);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/user');
        $this->client->submit($crawler->filter('form[action$="/resend-invitation"]')->form());

        self::assertResponseRedirects();
        self::assertEmailCount(1);
    }

    #[Test]
    public function an_active_account_offers_no_resend_and_refuses_one(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->admin($organization)->create());
        $active = UserFactory::new()->admin($organization)->create();

        $this->client->request(Request::METHOD_GET, self::detailUrl('/admin/user', $active->getId()));
        self::assertSelectorNotExists('form[action$="/resend-invitation"]');

        $this->client->request(Request::METHOD_POST, self::detailUrl('/admin/user', $active->getId()).'/resend-invitation', ['token' => 'x']);
        self::assertEmailCount(0);
    }

    #[Test]
    public function resending_without_a_valid_csrf_token_sends_nothing(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->admin($organization)->create());
        $pending = UserFactory::new()->admin($organization)->invited()->create();

        $this->client->request(Request::METHOD_POST, self::detailUrl('/admin/user', $pending->getId()).'/resend-invitation', ['token' => 'forged']);

        self::assertResponseRedirects();
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'La page a expiré');
    }

    #[Test]
    public function resending_is_post_only(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->admin($organization)->create());
        $pending = UserFactory::new()->admin($organization)->invited()->create();

        $this->client->request(Request::METHOD_GET, self::detailUrl('/admin/user', $pending->getId()).'/resend-invitation');

        self::assertEmailCount(0);
        self::assertFalse($this->client->getResponse()->isRedirection());
    }

    #[Test]
    public function the_owner_deletes_an_admin(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->owner($organization)->create());
        $admin = UserFactory::new()->admin($organization)->create();

        $this->delete($admin);

        self::assertResponseRedirects();
        self::assertNull($this->findOneOrNull(User::class, ['email' => $admin->getEmail()]));
    }

    #[Test]
    public function a_plain_admin_sees_no_delete_and_cannot_force_one(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->owner($organization)->create();
        $this->loginAs(UserFactory::new()->admin($organization)->create());
        $other = UserFactory::new()->admin($organization)->create();

        $this->client->request(Request::METHOD_GET, self::detailUrl('/admin/user', $other->getId()));
        self::assertSelectorNotExists('[data-action-name="delete"]');

        $this->delete($other);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->findOneOrNull(User::class, ['email' => $other->getEmail()]));
    }

    #[Test]
    public function nobody_deletes_the_owner(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create();
        $this->loginAs(UserFactory::new()->admin($organization)->create());

        $this->delete($owner);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->findOneOrNull(User::class, ['email' => $owner->getEmail()]));
    }

    #[Test]
    public function the_owner_cannot_delete_their_own_account(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create();
        $this->loginAs($owner);

        $this->client->request(Request::METHOD_GET, self::detailUrl('/admin/user', $owner->getId()));
        self::assertSelectorNotExists('[data-action-name="delete"]');

        $this->delete($owner);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->findOneOrNull(User::class, ['email' => $owner->getEmail()]));
    }

    #[Test]
    public function accounts_cannot_be_edited(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create();
        $this->loginAs($owner);

        $this->client->request(Request::METHOD_GET, self::detailUrl('/admin/user', $owner->getId()).'/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function an_address_already_in_use_is_refused(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAs(UserFactory::new()->owner($organization)->create(['email' => 'chef@asso.test']));

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/user/new');
        $this->client->submit($crawler->selectButton('Créer')->form(['User[email]' => 'chef@asso.test']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEmailCount(0);
    }

    /**
     * Through the page's own hidden delete form, so the token is EasyAdmin's real one.
     */
    private function delete(User $user): void
    {
        $token = self::deleteTokenIn($this->client->request(Request::METHOD_GET, '/admin/user'));

        $this->client->request(Request::METHOD_POST, self::detailUrl('/admin/user', $user->getId()).'/delete', ['token' => $token]);
    }
}
