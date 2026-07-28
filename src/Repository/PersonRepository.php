<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Person;
use App\ValueObject\Email;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
final class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * Backs the find-or-create in App\Declaration\DeclarationSubmitter.
     *
     * No organization argument: Person is TenantAware, so OrganizationFilter has
     * already restricted this query to the current tenant. Calling it from a CLI
     * context — where the filter is off by design — would match across tenants.
     */
    public function findOneByEmail(Email $email): ?Person
    {
        return $this->findOneBy(['email' => $email->value]);
    }
}
