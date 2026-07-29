<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FiscalPower;
use App\Repository\FiscalYearRepository;
use App\Tenant\TenantAware;
use App\Tenant\TenantAwareTrait;
use App\Validator\FiscalYearDoesNotOverlap;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

use function sprintf;

/**
 * An *exercice comptable* — the association's financial year, and the rates that
 * hold for it.
 *
 * THIS IS WHERE VALUATION RATES LIVE, and the reason is that they change. The
 * *barème kilométrique* is republished by the state; an association revisits what it
 * considers an hour of volunteering to be worth. A rate stored on the Task or on the
 * Organization would silently rewrite the valuation of years already closed — see
 * the note in App\Enum\FiscalPower, which reserved this home from the start.
 *
 * Two rates, each a default with optional per-type overrides:
 *
 *   - hourly, in CENTS, overridable per Task    (FiscalYearTaskRate)
 *   - mileage, in MILLIÈMES D'EURO per km,
 *     overridable per FiscalPower               (FiscalYearMileageRate)
 *
 * **Millièmes, not cents, for the mileage rate.** The published figures have three
 * decimals — 0,529 €/km for 3 CV et moins — so cents cannot hold them. An *amount*
 * in this codebase is always whole cents; a *rate* needs one more digit.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: FiscalYearRepository::class)]
#[ORM\Table(name: 'fiscal_year')]
#[ORM\UniqueConstraint(name: 'uniq_fiscal_year_organization_name', columns: ['organization_id', 'name'])]
#[UniqueEntity(
    fields: ['organization', 'name'],
    message: 'Cet exercice existe déjà dans cette association.',
    errorPath: 'name',
)]
// Overlap needs a repository lookup, so it cannot be an Assert\Callback on the
// entity — see App\Validator\FiscalYearDoesNotOverlapValidator.
#[FiscalYearDoesNotOverlap]
class FiscalYear implements TenantAware
{
    use TenantAwareTrait;

    public const int NAME_MAX_LENGTH = 40;

    /**
     * The first distance band of the barème stops here.
     *
     * Beyond it the published scale switches to a different formula — one with an
     * additive constant, keyed to the volunteer's cumulative kilometres for the year
     * — which this entity deliberately does not model. Anything past this figure is
     * therefore **understated**, and the ledger page says so rather than presenting a
     * number it cannot stand behind.
     */
    public const int FIRST_BAND_LIMIT_KM = 5000;

    /** 12,00 €/h. A starting point, not a recommendation. */
    public const int DEFAULT_HOURLY_RATE_CENTS = 1200;

    /** 1 000,00 €/h. Not a real rate; a guard against a slipped decimal point. */
    public const int MAX_HOURLY_RATE_CENTS = 100_000;

    /**
     * 0,529 €/km — the 3 CV et moins figure, which is the lowest automobile rate in
     * the barème and so the safest thing to default to.
     */
    public const int DEFAULT_MILLI_EUROS_PER_KM = 529;

    /** 10,000 €/km. Same purpose as MAX_HOURLY_RATE_CENTS. */
    public const int MAX_MILLI_EUROS_PER_KM = 10_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * How the association refers to the exercice: "2026", or "2025-2026" when it does
     * not follow the calendar year. Free text on purpose — an association whose year
     * runs September to August should be able to say so.
     */
    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'Donnez un nom à cet exercice, par exemple « 2026 ».')]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /**
     * Non-nullable, and defaulted by the constructor to the calendar year.
     *
     * The alternative — a nullable property so EasyAdmin can build the object before
     * the form binds — would mean a nullable column, and then the database would
     * permit an exercice with no dates while `contains()` quietly answered false for
     * everything. Defaulting instead keeps the column NOT NULL and pre-fills the form
     * with the year most associations actually use.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $beginsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\GreaterThan(
        propertyPath: 'beginsOn',
        message: 'La fin de l\'exercice doit suivre son début.',
    )]
    private DateTimeImmutable $endsOn;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_HOURLY_RATE_CENTS])]
    #[Assert\Positive(message: 'Le taux horaire doit être supérieur à zéro.')]
    #[Assert\LessThanOrEqual(
        value: self::MAX_HOURLY_RATE_CENTS,
        message: 'Le taux horaire ne peut pas dépasser {{ compared_value }} centimes.',
    )]
    private int $defaultHourlyRateCents = self::DEFAULT_HOURLY_RATE_CENTS;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_MILLI_EUROS_PER_KM])]
    #[Assert\Positive(message: 'Le barème kilométrique doit être supérieur à zéro.')]
    #[Assert\LessThanOrEqual(
        value: self::MAX_MILLI_EUROS_PER_KM,
        message: 'Le barème kilométrique ne peut pas dépasser {{ compared_value }} millièmes d\'euro.',
    )]
    private int $defaultMilliEurosPerKm = self::DEFAULT_MILLI_EUROS_PER_KM;

    /** @var Collection<int, FiscalYearTaskRate> */
    #[ORM\OneToMany(targetEntity: FiscalYearTaskRate::class, mappedBy: 'fiscalYear', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $taskRates;

    /** @var Collection<int, FiscalYearMileageRate> */
    #[ORM\OneToMany(targetEntity: FiscalYearMileageRate::class, mappedBy: 'fiscalYear', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mileageRates;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Takes the tenant, like App\Entity\Task: EasyAdmin instantiates entities with
     * `new $fqcn()` before binding the form, so the organization has to be supplied
     * when the object is built — see FiscalYearCrudController::createEntity().
     */
    public function __construct(Organization $organization)
    {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->taskRates = new ArrayCollection();
        $this->mileageRates = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();

        // The calendar year, as a starting point: it is what most associations use,
        // and it means the object is valid the moment it exists rather than only
        // after a form has filled it in.
        $thisYear = (int) $this->createdAt->format('Y');
        $this->beginsOn = new DateTimeImmutable(sprintf('%d-01-01', $thisYear));
        $this->endsOn = new DateTimeImmutable(sprintf('%d-12-31', $thisYear));
        $this->name = (string) $thisYear;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getBeginsOn(): DateTimeImmutable
    {
        return $this->beginsOn;
    }

    public function setBeginsOn(DateTimeImmutable $beginsOn): self
    {
        // Only the day matters; a stray time component would make contains() lie at
        // the edges of the exercice.
        $this->beginsOn = $beginsOn->setTime(0, 0);

        return $this;
    }

    public function getEndsOn(): DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(DateTimeImmutable $endsOn): self
    {
        $this->endsOn = $endsOn->setTime(0, 0);

        return $this;
    }

    /**
     * Whether a contributed action belongs to this exercice.
     *
     * THE ONE PLACE membership is decided, and it is decided on the action's START
     * date. An action spanning a year boundary therefore belongs wholly to the year it
     * began in — an accountant would not split five consecutive days of *travaux*
     * across two exercices, and neither does this.
     */
    public function contains(DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0);

        return $day >= $this->beginsOn && $day <= $this->endsOn;
    }

    public function getDefaultHourlyRateCents(): int
    {
        return $this->defaultHourlyRateCents;
    }

    public function setDefaultHourlyRateCents(int $defaultHourlyRateCents): self
    {
        $this->defaultHourlyRateCents = $defaultHourlyRateCents;

        return $this;
    }

    public function getDefaultMilliEurosPerKm(): int
    {
        return $this->defaultMilliEurosPerKm;
    }

    public function setDefaultMilliEurosPerKm(int $defaultMilliEurosPerKm): self
    {
        $this->defaultMilliEurosPerKm = $defaultMilliEurosPerKm;

        return $this;
    }

    /**
     * The hourly rate in force for a task in this exercice.
     *
     * THE ONE PLACE the hourly fallback lives. Reading a rate any other way gets the
     * default when an override exists, which is a wrong figure rather than a missing
     * one — the mistake Task::resolveHourlyRateCents() had to be documented against
     * before the rates moved here.
     */
    public function hourlyRateCentsFor(Task $task): int
    {
        foreach ($this->taskRates as $rate) {
            if ($rate->getTask()->getId()->equals($task->getId())) {
                return $rate->getHourlyRateCents();
            }
        }

        return $this->defaultHourlyRateCents;
    }

    /**
     * The mileage rate in force for a puissance fiscale in this exercice, in
     * millièmes d'euro per kilometre. THE ONE PLACE the mileage fallback lives.
     */
    public function milliEurosPerKmFor(FiscalPower $fiscalPower): int
    {
        foreach ($this->mileageRates as $rate) {
            if ($rate->getFiscalPower() === $fiscalPower) {
                return $rate->getMilliEurosPerKm();
            }
        }

        return $this->defaultMilliEurosPerKm;
    }

    /** @return Collection<int, FiscalYearTaskRate> */
    public function getTaskRates(): Collection
    {
        return $this->taskRates;
    }

    public function addTaskRate(FiscalYearTaskRate $rate): void
    {
        if (!$this->taskRates->contains($rate)) {
            $this->taskRates->add($rate);
        }
    }

    public function removeTaskRate(FiscalYearTaskRate $rate): void
    {
        $this->taskRates->removeElement($rate);
    }

    /** @return Collection<int, FiscalYearMileageRate> */
    public function getMileageRates(): Collection
    {
        return $this->mileageRates;
    }

    public function addMileageRate(FiscalYearMileageRate $rate): void
    {
        if (!$this->mileageRates->contains($rate)) {
            $this->mileageRates->add($rate);
        }
    }

    public function removeMileageRate(FiscalYearMileageRate $rate): void
    {
        $this->mileageRates->removeElement($rate);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
