<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Entity;

use App\Entity\Organization;
use App\Tenant\TenantAware;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A minimal TenantAware entity that exists only to test the tenant isolation
 * machinery.
 *
 * WHY IT EXISTS: this lot builds the multi-tenant foundation but no business
 * entity yet, so there is nothing real for OrganizationFilter to filter. Testing
 * the filter only at the unit level (does addFilterConstraint() return the right
 * SQL?) is exactly the kind of test that passes while the real thing leaks, so
 * the suite exercises it against a genuine Doctrine query instead.
 *
 * It is mapped only in the test environment (see the when@test block in
 * config/packages/doctrine.yaml), so its table is created by Foundry's
 * ResetDatabase trait alongside the real ones and never appears in a migration
 * or in the dev and prod schemas.
 *
 * DELETE THIS once a real TenantAware business entity exists and can carry the
 * isolation test itself.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant_probe')]
class TenantProbe implements TenantAware
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $label;

    public function __construct(Organization $organization, string $label)
    {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->label = $label;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
