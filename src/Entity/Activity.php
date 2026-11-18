<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ActivityType;
use App\Form\Declaration\ActionDraft;
use App\Repository\ActivityRepository;
use App\Tenant\TenantAware;
use App\Tenant\TenantAwareTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An event or an action of the association — the occasion a volunteer gave their time
 * for: *Régate de printemps*, *Entretien des bateaux*. A declaration line links to one,
 * or, when none fits, keeps a free-text title (`DeclarationAction::$title`) (#44).
 *
 * Not a App\Entity\Task: a task is the kind of work done, and carries a valuation rate;
 * this is where and when it was done, and carries none.
 *
 * The dates are optional. An event that has not begun is not offered on the public form
 * — a line must be over to be declared — and that is all they decide: a line's own date
 * is not checked against them.
 *
 * Unique by (name, begin date): a yearly event repeats its name with another date, and
 * the same name twice with no date at all would be two indistinguishable choices. Two
 * partial indexes, because a plain unique index treats NULL dates as distinct.
 *
 * Tenant-scoped, so `OrganizationFilter` restricts every query.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'activity')]
#[ORM\UniqueConstraint(
    name: 'uniq_activity_organization_name_begins_on',
    columns: ['organization_id', 'name', 'begins_on'],
    options: ['where' => '(begins_on IS NOT NULL)'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_activity_organization_name_undated',
    columns: ['organization_id', 'name'],
    options: ['where' => '(begins_on IS NULL)'],
)]
#[UniqueEntity(
    fields: ['organization', 'name', 'beginsOn'],
    message: 'Un événement ou une action de ce nom existe déjà à cette date.',
    errorPath: 'name',
    ignoreNull: false,
)]
class Activity implements TenantAware
{
    use TenantAwareTrait;

    public const int NAME_MAX_LENGTH = DeclarationAction::TITLE_MAX_LENGTH;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'Indiquez le nom.')]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /**
     * Deliberately left uninitialised, with no default: EasyAdmin builds the entity
     * before the form supplies a type, and choosing one for the admin would file
     * actions as events. The getter answers null until it is set, which is what the
     * NotNull below reads; the column itself stays NOT NULL.
     * A nullable property would need a nullable column, hence the suppression.
     */
    #[ORM\Column(enumType: ActivityType::class)]
    #[Assert\NotNull(message: 'Indiquez s\'il s\'agit d\'un événement ou d\'une action.')]
    // @phpstan-ignore property.uninitialized (deliberate: see the docblock above)
    private ActivityType $type;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $beginsOn = null;

    /** Never set without a begin date; a one-day event has only that one. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\Expression(
        'value === null or this.getBeginsOn() !== null',
        message: 'Indiquez aussi la date de début.',
    )]
    #[Assert\GreaterThanOrEqual(
        propertyPath: 'beginsOn',
        message: 'La date de fin ne peut pas précéder la date de début.',
    )]
    private ?DateTimeImmutable $endsOn = null;

    /**
     * What the activity usually asks of a volunteer: a number of consecutive days, and
     * the hours given in total over them (#46). Both optional, and only a suggestion —
     * choosing the activity on the declaration form pre-fills the line with them (and
     * two journeys a day), in the browser; the volunteer still declares what they did.
     *
     * Independent of the dates above: a three-day event may ask two days of each
     * volunteer. Held to a line's own limits, so a pre-filled line is always a valid one.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(
        notInRangeMessage: 'Le nombre de jours doit être compris entre {{ min }} et {{ max }}.',
        min: 1,
        max: ActionDraft::MAX_CONSECUTIVE_DAYS,
    )]
    private ?int $defaultDays = null;

    /** A DECIMAL(5,2) string, as DeclarationAction::$workHours — never a float. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    #[Assert\Positive(message: 'Le nombre d\'heures doit être supérieur à zéro.')]
    #[Assert\LessThanOrEqual(ActionDraft::MAX_WORK_HOURS)]
    private ?string $defaultWorkHours = null;

    /**
     * An inactive activity disappears from the public form but stays readable on the
     * lines that reference it — the alternative to deleting, which the FK refuses once
     * it is used. Nothing retires one automatically, however old.
     */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Takes only the tenant, for the reason given on App\Entity\Task: EasyAdmin builds
     * the object before binding the form — see ActivityCrudController::createEntity().
     */
    public function __construct(Organization $organization)
    {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->createdAt = new DateTimeImmutable();
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

    public function getType(): ?ActivityType
    {
        return $this->type ?? null;
    }

    /** Null — a form submitted with nothing chosen — leaves the type as it was. */
    public function setType(?ActivityType $type): self
    {
        if (null !== $type) {
            $this->type = $type;
        }

        return $this;
    }

    public function getBeginsOn(): ?DateTimeImmutable
    {
        return $this->beginsOn;
    }

    public function setBeginsOn(?DateTimeImmutable $beginsOn): self
    {
        $this->beginsOn = $beginsOn?->setTime(0, 0);

        return $this;
    }

    public function getEndsOn(): ?DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(?DateTimeImmutable $endsOn): self
    {
        $this->endsOn = $endsOn?->setTime(0, 0);

        return $this;
    }

    public function getDefaultDays(): ?int
    {
        return $this->defaultDays;
    }

    public function setDefaultDays(?int $defaultDays): self
    {
        $this->defaultDays = $defaultDays;

        return $this;
    }

    public function getDefaultWorkHours(): ?string
    {
        return $this->defaultWorkHours;
    }

    public function setDefaultWorkHours(?string $defaultWorkHours): self
    {
        $this->defaultWorkHours = $defaultWorkHours;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
