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
 */
#[AsTaggedItem(priority: 0)]
final readonly class UserTenantResolver implements TenantResolver
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function resolve(Request $request): ?Organization
    {
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
