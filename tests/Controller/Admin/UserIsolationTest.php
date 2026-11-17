<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\UserCrudController;
use App\Entity\Organization;
use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\Tests\Support\ReloadsEntities;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * User is not TenantAware, so nothing but App\Controller\Admin\UserCrudController
 * and UserVoter keep one association out of another's accounts. Every entry point,
 * one test each — the pattern of DeclarationActionIsolationTest.
 *
 * The EntityManager is cleared before each request: WebTestCase shares it with the
 * requests, and an instance Foundry left in the identity map would reach the
 * controller without any query being made.
 */
final class UserIsolationTest extends WebTestCase
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
    public function the_list_shows_only_the_current_associations_accounts(): void
    {
        $mine = $this->organization('les-jardins');
        $theirs = $this->organization('les-voiles');
        $me = UserFactory::new()->owner($mine)->create(['email' => 'moi@jardins.test']);
        UserFactory::new()->owner($theirs)->create(['email' => 'eux@voiles.test']);
        $this->loginAs($me);

        $this->requestFresh(Request::METHOD_GET, '/admin/user');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'moi@jardins.test');
        self::assertSelectorTextNotContains('body', 'eux@voiles.test');
    }

    #[Test]
    public function the_autocomplete_is_scoped_too(): void
    {
        $mine = $this->organization('les-jardins');
        $me = UserFactory::new()->owner($mine)->create(['email' => 'moi@jardins.test']);
        UserFactory::new()->owner($this->organization('les-voiles'))->create(['email' => 'eux@voiles.test']);
        $this->loginAs($me);

        // EasyAdmin refuses an autocomplete request that does not say which field it
        // serves; without this context it answers a 500 before running any query,
        // and the assertion below would pass on an error page.
        $this->requestFresh(Request::METHOD_GET, '/admin/user/autocomplete', [
            'query' => 'test',
            'autocompleteContext' => [
                'crudControllerFqcn' => UserCrudController::class,
                'originatingPage' => 'index',
                'propertyName' => 'email',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('moi@jardins.test', $body);
        self::assertStringNotContainsString('eux@voiles.test', $body);
    }

    #[Test]
    public function another_associations_account_detail_is_forbidden(): void
    {
        $me = UserFactory::new()->owner($this->organization('les-jardins'))->create();
        $theirs = UserFactory::new()->owner($this->organization('les-voiles'))->create();
        $this->loginAs($me);

        $this->requestFresh(Request::METHOD_GET, '/admin/user/'.$theirs->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function another_associations_account_cannot_be_deleted(): void
    {
        $me = UserFactory::new()->owner($this->organization('les-jardins'))->create();
        $theirs = UserFactory::new()->admin($this->organization('les-voiles'))->create();
        $this->loginAs($me);

        $token = self::deleteTokenIn($this->requestFresh(Request::METHOD_GET, '/admin/user'));
        $this->requestFresh(Request::METHOD_POST, '/admin/user/'.$theirs->getId()->toRfc4122().'/delete', ['token' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertTrue(null !== $this->findOneOrNull(User::class, ['id' => $theirs->getId()]));
    }

    #[Test]
    public function another_associations_invitation_cannot_be_resent(): void
    {
        $me = UserFactory::new()->owner($this->organization('les-jardins'))->create();
        $theirs = UserFactory::new()->admin($this->organization('les-voiles'))->invited()->create();
        $this->loginAs($me);

        // A real token: a forged one is refused before the tenant check is reached.
        $this->client->request(Request::METHOD_GET, '/admin');
        $token = $this->mintActionToken('resendInvitation', $theirs->getId());

        $this->requestFresh(Request::METHOD_POST, '/admin/user/'.$theirs->getId()->toRfc4122().'/resend-invitation', ['token' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertEmailCount(0);
    }

    private function organization(string $slug): Organization
    {
        return OrganizationFactory::createOne(['slug' => $slug]);
    }
}
