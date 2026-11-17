<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Organization;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * One way of working out which organization a request belongs to.
 *
 * Implementations are tried in priority order by TenantRequestListener; the
 * first one to return an Organization wins. Return null to mean "this strategy
 * does not apply to this request", not "access denied".
 *
 * A resolver cannot say "this request is mine and it has no tenant": the next one
 * is tried regardless. So a strategy that must not be a fallback for another says
 * so itself — see UserTenantResolver, which ignores a request carrying a slug.
 */
#[AutoconfigureTag('app.tenant_resolver')]
interface TenantResolver
{
    public function resolve(Request $request): ?Organization;
}
