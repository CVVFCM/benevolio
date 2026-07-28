<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventTypeRepository;
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
 * A kind of event a volunteer can contribute to — *travaux*, *régate*, and
 * whatever else the association actually runs.
 *
 * This used to be a backed enum. It became an entity so each association manages
 * its own list: a sailing club and a neighbourhood association have nothing in
 * common here, and adding a category should not need a deployment.
 *
 * Tenant-scoped, so `OrganizationFilter` restricts every query. A new association
 * gets a starter list from App\Organization\DefaultEventTypes.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: EventTypeRepository::class)]
#[ORM\Table(name: 'event_type')]
#[ORM\UniqueConstraint(name: 'uniq_event_type_organization_name', columns: ['organization_id', 'name'])]
#[UniqueEntity(
    fields: ['organization', 'name'],
    message: 'Ce type d\'événement existe déjà dans cette association.',
    errorPath: 'name',
)]
class EventType implements TenantAware
{
    use TenantAwareTrait;

    public const int NAME_MAX_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'Indiquez le nom du type d\'événement.')]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /**
     * An inactive type disappears from the public form but stays readable on the
     * actions that already reference it. This is the alternative to deleting,
     * which the FK refuses once a type is used — a validated declaration is a
     * supporting document and must not lose the label it was filed under.
     */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Takes no argument beyond the tenant: EasyAdmin instantiates entities with
     * `new $fqcn()` before binding the form, and this is the one entity admins
     * genuinely create by hand. The organization is passed because nothing in the
     * form supplies it — see EventTypeCrudController::createEntity().
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
