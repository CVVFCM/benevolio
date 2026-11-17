<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
final class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Resolves the tenant behind a public /a/{organizationSlug}/… URL.
     *
     * Inactive organizations are not returned, so deactivating one closes both
     * its backoffice and its public declaration forms.
     */
    public function findActiveBySlug(string $slug): ?Organization
    {
        return $this->findOneBy(['slug' => $slug, 'active' => true]);
    }

    /**
     * The associations named on the public home page: the ones that opted in, and
     * only while active — a closed association's form would 404.
     *
     * @return list<Organization>
     */
    public function findListedOnHomepage(): array
    {
        return $this->findBy(['active' => true, 'listedOnHomepage' => true], ['name' => 'ASC']);
    }
}
