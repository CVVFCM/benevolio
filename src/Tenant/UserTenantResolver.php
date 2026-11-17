<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Organization;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the tenant from the logged-in account, which is how the /admin
 * backoffice is scoped: an organization admin sees their own association and
 * nothing else, with no slug in the URL.
 *
 * Returns null for a platform super-admin, who is attached to no organization —
 * that is what leaves /platform unfiltered.
 *
 * Depends on TokenStorageInterface rather than the SecurityBundle Security
 * helper: it is the narrowest contract that answers "who is logged in", and it
 * keeps this class testable without a container.
 *
 * Runs after UrlPrefixTenantResolver (lower priority).
 *
 * TRAP — returns before touching the token when the request carries nothing to
 * authenticate with. Reading the token on a lazy firewall counts as using the
 * session, and Symfony then marks the response `private, must-revalidate`: every
 * response, since this runs on every request. That silently made the public home
 * page uncacheable and /health with it. (Symfony 8.1.1 did not count this first
 * read; 8.1.11 fixed that, which is how the leak surfaced.) Same guard, same reason,
 * as App\Security\ChoosePasswordEnforcer — plus the remember-me cookie, because an
 * admin coming back on that cookie alone still needs their tenant resolved.
 */
#[AsTaggedItem(priority: 0)]
final readonly class UserTenantResolver implements TenantResolver
{
    /** Symfony's default name; config/packages/security.yaml does not override it. */
    private const string REMEMBER_ME_COOKIE = 'REMEMBERME';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function resolve(Request $request): ?Organization
    {
        if (!$request->hasPreviousSession() && !$request->cookies->has(self::REMEMBER_ME_COOKIE)) {
            return null;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            return null;
        }

        $organization = $user->getOrganization();

        // A deactivated organization must not be reachable, even by its own admin.
        if (null === $organization || !$organization->isActive()) {
            return null;
        }

        return $organization;
    }
}
