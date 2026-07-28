<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Organization;

/**
 * Marks an entity as belonging to exactly one Organization.
 *
 * App\Doctrine\Filter\OrganizationFilter adds a WHERE clause to every query
 * touching an entity that implements this interface, and to nothing else. So
 * implementing it is what makes an entity tenant-scoped — use TenantAwareTrait
 * to get the mapping and the accessor for free.
 *
 * Organization and User do NOT implement it: Organization *is* the tenant, and
 * filtering User would break authentication (the user provider runs before the
 * tenant is known).
 */
interface TenantAware
{
    public function getOrganization(): Organization;
}
