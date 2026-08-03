<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FiscalYear;
use App\Entity\Receipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Receipt>
 */
final class ReceiptRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly FiscalYearRepository $fiscalYears,
    ) {
        parent::__construct($registry, Receipt::class);
    }

    /**
     * Whether any receipt has been issued for a civil year this exercice prices.
     *
     * Asked by App\State\Listener\FiscalYearReopenGuard: reopening a closed exercice is fine
     * until its rates have produced a tax document, and forbidden afterwards.
     *
     * Receipt IS TenantAware, so OrganizationFilter scopes this in a request. The explicit
     * organization clause is what makes it correct in CLI too, where the filter is off by design
     * — without it, another association's 2026 receipt would lock this exercice.
     */
    public function existsForCivilYearsPricedBy(FiscalYear $fiscalYear): bool
    {
        $years = $this->fiscalYears->civilYearsPricedBy($fiscalYear);

        if ([] === $years) {
            return false;
        }

        $count = $this->createQueryBuilder('receipt')
            ->select('COUNT(receipt.id)')
            ->andWhere('receipt.organization = :organization')
            ->andWhere('receipt.year IN (:years)')
            ->setParameter('organization', $fiscalYear->getOrganization()->getId(), 'uuid')
            ->setParameter('years', $years)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
