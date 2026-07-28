<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use App\Tenant\TenantAware;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

use function sprintf;

/**
 * Restricts every query on a TenantAware entity to the current organization.
 *
 * Declared with `enabled: false` in config/packages/doctrine.yaml:
 * App\Tenant\TenantRequestListener arms it per request with the resolved tenant,
 * and disables it when there is none (platform routes, anonymous requests).
 *
 * FOOTGUN: because it is armed by an HTTP listener, this filter is OFF in CLI
 * context — console commands, migrations and fixtures see every tenant's rows.
 * That is intentional (a migration must touch all tenants), but any command
 * that acts on behalf of one organization has to scope its queries itself.
 */
final class OrganizationFilter extends SQLFilter
{
    public const string NAME = 'organization';
    public const string PARAMETER = 'organization_id';

    /**
     * Association on TenantAware entities that carries the tenant. Provided by
     * App\Tenant\TenantAwareTrait.
     */
    private const string ASSOCIATION = 'organization';

    /**
     * @param ClassMetadata<object> $targetEntity
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Only tenant-scoped entities are filtered. Organization itself and User
        // are not TenantAware, so they are returned unfiltered — which is what
        // lets the user provider authenticate before a tenant exists.
        if (!is_a($targetEntity->getName(), TenantAware::class, true)) {
            return '';
        }

        // No parameter means the listener has not armed the filter. Returning an
        // impossible condition would break every query; the filter is disabled
        // rather than parameterless in that case, so this is a safety net for a
        // misconfiguration and must fail loudly instead of leaking rows.
        if (!$this->hasParameter(self::PARAMETER)) {
            return sprintf('%s.%s IS NULL', $targetTableAlias, $this->joinColumn($targetEntity));
        }

        return sprintf(
            '%s.%s = %s',
            $targetTableAlias,
            $this->joinColumn($targetEntity),
            $this->getParameter(self::PARAMETER),
        );
    }

    /**
     * Reads the column name from the mapping instead of hardcoding
     * "organization_id", so it keeps working if the naming strategy changes.
     *
     * @param ClassMetadata<object> $targetEntity
     */
    private function joinColumn(ClassMetadata $targetEntity): string
    {
        return $targetEntity->getSingleAssociationJoinColumnName(self::ASSOCIATION);
    }
}
