<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Organization;
use Doctrine\ORM\Mapping as ORM;

/**
 * Gives a TenantAware entity its Organization association in one line.
 *
 * The property is private to the trait, which — since traits are flattened into
 * the using class — means the entity's own constructor can assign it while
 * nothing outside can reassign it. Tenant reassignment is not a supported
 * operation: there is deliberately no setter.
 *
 * Usage:
 *
 *     class Contribution implements TenantAware
 *     {
 *         use TenantAwareTrait;
 *
 *         public function __construct(Organization $organization)
 *         {
 *             $this->organization = $organization;
 *         }
 *     }
 */
trait TenantAwareTrait
{
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    public function getOrganization(): Organization
    {
        return $this->organization;
    }
}
