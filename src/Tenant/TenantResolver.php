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
 */
#[AutoconfigureTag('app.tenant_resolver')]
interface TenantResolver
{
    public function resolve(Request $request): ?Organization;
}
