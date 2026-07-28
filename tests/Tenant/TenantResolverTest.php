<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Tenant\Exception\TenantNotResolvedException;
use App\Tenant\TenantContext;
use App\Tenant\UrlPrefixTenantResolver;
use App\Tenant\UserTenantResolver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class TenantResolverTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function url_prefix_resolver_resolves_an_active_organization_from_the_route_slug(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $resolved = $this->urlPrefixResolver()->resolve($this->requestWithSlug('les-jardins'));

        self::assertNotNull($resolved);
        self::assertSame('les-jardins', $resolved->getSlug());
    }

    #[Test]
    public function url_prefix_resolver_ignores_a_request_without_the_route_attribute(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        self::assertNull($this->urlPrefixResolver()->resolve(new Request()));
    }

    #[Test]
    public function url_prefix_resolver_refuses_an_unknown_slug(): void
    {
        self::assertNull($this->urlPrefixResolver()->resolve($this->requestWithSlug('does-not-exist')));
    }

    /**
     * Deactivating an association must close its public forms, not just its
     * backoffice.
     */
    #[Test]
    public function url_prefix_resolver_refuses_an_inactive_organization(): void
    {
        OrganizationFactory::new()->inactive()->create(['slug' => 'fermee']);

        self::assertNull($this->urlPrefixResolver()->resolve($this->requestWithSlug('fermee')));
    }

    #[Test]
    public function user_resolver_resolves_the_organization_of_the_logged_in_admin(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $admin = UserFactory::new()->admin($organization)->create();

        $resolved = $this->userResolver($admin)->resolve(new Request());

        self::assertNotNull($resolved);
        self::assertSame('les-jardins', $resolved->getSlug());
    }

    /**
     * A super-admin belongs to no organization. That is precisely what leaves the
     * Doctrine filter disabled on /platform.
     */
    #[Test]
    public function user_resolver_resolves_nothing_for_a_super_admin(): void
    {
        $superAdmin = UserFactory::new()->superAdmin()->create();

        self::assertNull($this->userResolver($superAdmin)->resolve(new Request()));
    }

    #[Test]
    public function user_resolver_resolves_nothing_for_an_anonymous_request(): void
    {
        self::assertNull($this->userResolver(null)->resolve(new Request()));
    }

    #[Test]
    public function user_resolver_refuses_an_admin_of_an_inactive_organization(): void
    {
        $organization = OrganizationFactory::new()->inactive()->create();
        $admin = UserFactory::new()->admin($organization)->create();

        self::assertNull($this->userResolver($admin)->resolve(new Request()));
    }

    #[Test]
    public function tenant_context_throws_when_read_with_no_resolved_tenant(): void
    {
        $context = new TenantContext();

        self::assertFalse($context->hasOrganization());
        self::assertNull($context->tryGetOrganization());

        $this->expectException(TenantNotResolvedException::class);
        $context->getOrganization();
    }

    #[Test]
    public function tenant_context_forgets_its_tenant_on_reset(): void
    {
        // Guards against cross-request tenant leakage under FrankenPHP worker mode,
        // where the container survives between requests.
        $context = new TenantContext();
        $context->setOrganization(OrganizationFactory::createOne());
        self::assertTrue($context->hasOrganization());

        $context->reset();

        self::assertFalse($context->hasOrganization());
    }

    private function urlPrefixResolver(): UrlPrefixTenantResolver
    {
        self::bootKernel();

        return self::getContainer()->get(UrlPrefixTenantResolver::class);
    }

    private function userResolver(?UserInterface $user): UserTenantResolver
    {
        $tokenStorage = new TokenStorage();

        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return new UserTenantResolver($tokenStorage);
    }

    private function requestWithSlug(string $slug): Request
    {
        $request = new Request();
        $request->attributes->set(UrlPrefixTenantResolver::ROUTE_ATTRIBUTE, $slug);

        return $request;
    }
}
