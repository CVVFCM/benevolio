<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventType;
use App\Enum\FiscalPower;
use App\Repository\DeclarationActionRepository;
use App\State\DeclarationActionState;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One contributed action inside a declaration: an event the person helped at, the
 * hours they gave, and the travel they paid for.
 *
 * A single line therefore carries BOTH kinds of contribution, which are accounted
 * for differently: the hours are donated time (864/870, off balance sheet, never
 * receiptable), while the travel is an expense the person waives reimbursement of
 * and so becomes a donation (754x, receiptable). Keep that in mind before
 * summing anything.
 *
 * TENANCY — DELIBERATE EXCEPTION: this is the one business entity that does NOT
 * implement App\Tenant\TenantAware, so OrganizationFilter does NOT scope queries
 * on it. It is reachable through its Declaration, which is tenant-scoped. Any
 * code that queries DeclarationAction directly must scope itself — see
 * App\Controller\Admin\DeclarationActionCrudController, which joins declaration
 * in createIndexQueryBuilder() and guards single-record access with
 * App\Security\Voter\DeclarationActionVoter.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: DeclarationActionRepository::class)]
#[ORM\Table(name: 'declaration_action')]
#[Assert\Expression(
    'this.usesOwnVehicle() ? this.getFiscalPower() !== null : this.getFiscalPower() === null',
    message: 'La puissance fiscale est requise lorsque le bénévole utilise son propre véhicule, et doit rester vide sinon.',
)]
class DeclarationAction
{
    public const int TITLE_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Declaration::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Declaration $declaration;

    #[ORM\Column(enumType: DeclarationActionState::class)]
    private DeclarationActionState $state = DeclarationActionState::SUBMITTED;

    #[ORM\Column(enumType: EventType::class)]
    private EventType $eventType;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title;

    /** What the volunteer actually did, in their own words. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    /** First day of the action; it may span several consecutive days. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $date;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $consecutiveDays;

    /** Number of one-way journeys made for this action. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $journeys;

    /**
     * Kilometres of ONE journey, ONE WAY. A return trip is two journeys.
     * The total is distanceKm × journeys — see getTotalDistanceKm().
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $distanceKm;

    #[ORM\Column]
    private bool $ownVehicle;

    /**
     * Null unless the person came in their own vehicle. Required in that case —
     * enforced by the Assert\Expression on this class, since a NOT NULL column
     * cannot express a conditional requirement.
     */
    #[ORM\Column(enumType: FiscalPower::class, nullable: true)]
    private ?FiscalPower $fiscalPower;

    /** Hours given, as a DECIMAL(5,2) string — e.g. "7.50" for seven and a half. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $workHours;

    public function __construct(
        Declaration $declaration,
        EventType $eventType,
        string $title,
        ?string $description,
        DateTimeImmutable $date,
        int $consecutiveDays,
        int $journeys,
        int $distanceKm,
        bool $ownVehicle,
        ?FiscalPower $fiscalPower,
        string $workHours,
    ) {
        $this->id = Uuid::v7();
        $this->declaration = $declaration;
        $this->eventType = $eventType;
        $this->title = $title;
        $this->description = $description;
        // Only the day matters; a stray time component would break date grouping.
        $this->date = $date->setTime(0, 0);
        $this->consecutiveDays = $consecutiveDays;
        $this->journeys = $journeys;
        $this->distanceKm = $distanceKm;
        $this->ownVehicle = $ownVehicle;
        $this->fiscalPower = $ownVehicle ? $fiscalPower : null;
        $this->workHours = $workHours;

        $declaration->addAction($this);
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDeclaration(): Declaration
    {
        return $this->declaration;
    }

    /**
     * The tenant this action belongs to, reached through its declaration. This is
     * what stands in for TenantAware::getOrganization() on this entity.
     */
    public function getOrganization(): Organization
    {
        return $this->declaration->getOrganization();
    }

    public function getState(): DeclarationActionState
    {
        return $this->state;
    }

    /**
     * Set by the finite state machine only. Go through Finite\StateMachine::apply()
     * so guards and listeners run.
     */
    public function setState(DeclarationActionState $state): void
    {
        $this->state = $state;
    }

    public function getEventType(): EventType
    {
        return $this->eventType;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function getConsecutiveDays(): int
    {
        return $this->consecutiveDays;
    }

    public function getJourneys(): int
    {
        return $this->journeys;
    }

    public function getDistanceKm(): int
    {
        return $this->distanceKm;
    }

    /** One-way distance × number of journeys. */
    public function getTotalDistanceKm(): int
    {
        return $this->distanceKm * $this->journeys;
    }

    public function usesOwnVehicle(): bool
    {
        return $this->ownVehicle;
    }

    public function getFiscalPower(): ?FiscalPower
    {
        return $this->fiscalPower;
    }

    public function getWorkHours(): string
    {
        return $this->workHours;
    }

    /**
     * The declared hours as an exact number of hundredths, so totals can be summed
     * without floating point. See Declaration::getTotalWorkHours().
     */
    public function getWorkHoursInHundredths(): int
    {
        $parts = explode('.', $this->workHours, 2);
        $whole = $parts[0];
        // DECIMAL(5,2) always comes back from PostgreSQL with two decimals, but a
        // value assigned in PHP may be a bare "7".
        $fraction = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');

        return (int) $whole * 100 + (int) $fraction;
    }
}
