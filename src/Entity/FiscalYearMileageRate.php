<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FiscalPower;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

use function abs;
use function intdiv;
use function sprintf;

/**
 * The barème figure for one puissance fiscale in one exercice.
 *
 * IN MILLIÈMES D'EURO PER KILOMETRE, not cents: the published rates have three
 * decimals (0,529 → 0,697 €/km for automobiles), so cents would silently round the
 * law. See App\Entity\FiscalYear.
 *
 * A row per bracket rather than five nullable columns, so adding a bracket to
 * App\Enum\FiscalPower does not need a migration.
 *
 * TENANCY: not TenantAware, reachable only through its FiscalYear — see
 * App\Entity\FiscalYearTaskRate, which makes the same exception for the same reason.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fiscal_year_mileage_rate')]
#[ORM\UniqueConstraint(name: 'uniq_fiscal_year_mileage_rate', columns: ['fiscal_year_id', 'fiscal_power'])]
class FiscalYearMileageRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: FiscalYear::class, inversedBy: 'mileageRates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FiscalYear $fiscalYear;

    #[ORM\Column(enumType: FiscalPower::class)]
    private FiscalPower $fiscalPower;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive(message: 'Le barème kilométrique doit être supérieur à zéro.')]
    #[Assert\LessThanOrEqual(
        value: FiscalYear::MAX_MILLI_EUROS_PER_KM,
        message: 'Le barème kilométrique ne peut pas dépasser {{ compared_value }} millièmes d\'euro.',
    )]
    private int $milliEurosPerKm = FiscalYear::DEFAULT_MILLI_EUROS_PER_KM;

    public function __construct(FiscalYear $fiscalYear, FiscalPower $fiscalPower)
    {
        $this->id = Uuid::v7();
        $this->fiscalYear = $fiscalYear;
        $this->fiscalPower = $fiscalPower;

        $fiscalYear->addMileageRate($this);
    }

    /**
     * "5 CV : 0,636 €/km" — see FiscalYearTaskRate::__toString() for why this exists.
     *
     * Three decimals, because that is the unit: millièmes d'euro per kilometre, which is what
     * the published barème uses and what cents would round away.
     */
    public function __toString(): string
    {
        return sprintf(
            "%s : %d,%03d\u{a0}€/km",
            $this->fiscalPower->label(),
            intdiv($this->milliEurosPerKm, 1000),
            abs($this->milliEurosPerKm % 1000),
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    public function getFiscalPower(): FiscalPower
    {
        return $this->fiscalPower;
    }

    public function getMilliEurosPerKm(): int
    {
        return $this->milliEurosPerKm;
    }

    public function setMilliEurosPerKm(int $milliEurosPerKm): self
    {
        $this->milliEurosPerKm = $milliEurosPerKm;

        return $this;
    }
}
