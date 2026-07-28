<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Declaration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Declaration>
 */
final class DeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Declaration::class);
    }

    /**
     * Finds the declaration a confirmation link points at.
     *
     * Declaration is TenantAware, so the tenant filter has already restricted this
     * to the association whose URL the link used — a token cannot be redeemed
     * through another association's address.
     */
    public function findOneByConfirmationToken(string $token): ?Declaration
    {
        return $this->findOneBy(['confirmationToken' => $token]);
    }
}
