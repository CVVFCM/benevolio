<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Resolves the tenant from the {slug} placeholder of the public volunteer
 * routes, which all live under /a/{slug}/….
 *
 * This is the strategy that works for anonymous visitors: volunteers have no
 * account, so their declaration form cannot derive the organization from a
 * logged-in user.
 *
 * Runs before UserTenantResolver: when a URL names an organization explicitly,
 * that is the one that counts.
 */
#[AsTaggedItem(priority: 100)]
final readonly class UrlPrefixTenantResolver implements TenantResolver
{
    /**
     * Route attribute holding the organization slug. Public volunteer routes
     * must declare their path as /a/{organizationSlug}/….
     */
    public const string ROUTE_ATTRIBUTE = 'organizationSlug';

    public function __construct(
        private OrganizationRepository $organizations,
    ) {
    }

    public function resolve(Request $request): ?Organization
    {
        $slug = $request->attributes->get(self::ROUTE_ATTRIBUTE);

        if (!is_string($slug) || '' === $slug) {
            return null;
        }

        return $this->organizations->findActiveBySlug($slug);
    }
}
