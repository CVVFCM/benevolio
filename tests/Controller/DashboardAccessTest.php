<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Access rules for the two backoffices: /admin belongs to an organization admin,
 * /platform to a platform super-admin, and neither is reachable anonymously.
 *
 * NOTE on ordering: the client is created in setUp(), before any factory runs.
 * Foundry boots the kernel the first time a factory persists something, and
 * WebTestCase refuses to create a client once the kernel is already booted.
 */
final class DashboardAccessTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function anonymous_users_are_sent_to_the_login_form(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/login');

        $this->client->request('GET', '/platform');
        self::assertResponseRedirects('http://localhost/login');
    }

    #[Test]
    public function an_organization_admin_reaches_its_own_backoffice(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne(['name' => 'Les Jardins Partagés']));

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        // The dashboard title comes from the resolved tenant, which proves the
        // request-time tenant resolution ran.
        self::assertStringContainsString(
            'Les Jardins Partagés',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function an_organization_admin_cannot_reach_the_platform_backoffice(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $this->client->request('GET', '/platform');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * The platform CRUDs must not be reachable through the tenant dashboard,
     * where the Doctrine tenant filter is armed. EasyAdmin would register them
     * under every dashboard without the deniedControllers list on
     * App\Controller\Admin\DashboardController.
     */
    #[Test]
    public function the_organization_backoffice_does_not_expose_the_platform_cruds(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $this->client->request('GET', '/admin/organization');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', '/admin/user');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function a_super_admin_reaches_the_platform_backoffice(): void
    {
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->client->request('GET', '/platform');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function a_super_admin_lists_every_organization_on_the_platform_backoffice(): void
    {
        OrganizationFactory::createOne(['name' => 'Première Association']);
        OrganizationFactory::createOne(['name' => 'Seconde Association']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->client->request('GET', '/platform/organization');

        // No tenant resolves for a super-admin, so the filter stays disabled and
        // the platform backoffice sees across tenants.
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Première Association', $content);
        self::assertStringContainsString('Seconde Association', $content);
    }

    /**
     * A super-admin inherits ROLE_ADMIN through the role hierarchy, so /admin is
     * authorized — but no tenant resolves for them, so the dashboard has no
     * organization to render. It must fail loudly rather than fall back to some
     * other tenant's data.
     */
    #[Test]
    public function a_super_admin_has_no_tenant_on_the_organization_backoffice(): void
    {
        $this->client->catchExceptions(false);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->expectException(LogicException::class);
        $this->client->request('GET', '/admin');
    }

    private function loginAsAdminOf(Organization $organization): void
    {
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());
    }
}
