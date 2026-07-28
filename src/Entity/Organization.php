<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The tenant: one association loi 1901.
 *
 * Every business entity belongs to exactly one Organization through
 * App\Tenant\TenantAwareTrait, and App\Doctrine\Filter\OrganizationFilter keeps
 * queries inside the current one. Organization itself is NOT tenant-aware — it
 * is the tenant.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[UniqueEntity(fields: ['slug'], message: 'Ce raccourci est déjà utilisé par une autre association.')]
class Organization
{
    public const int NAME_MAX_LENGTH = 150;
    public const int SLUG_MAX_LENGTH = 100;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /**
     * Used in the public volunteer URLs (/a/{slug}/…), so it must stay stable
     * once communicated and must not collide with another organization.
     */
    #[ORM\Column(length: self::SLUG_MAX_LENGTH, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::SLUG_MAX_LENGTH)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'Le raccourci ne peut contenir que des minuscules, des chiffres et des tirets.',
    )]
    private string $slug = '';

    /**
     * An inactive organization keeps its data but is refused at tenant
     * resolution, so both its admin backoffice and its public forms are closed.
     */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * The constructor takes no argument because EasyAdmin instantiates entities
     * with `new $fqcn()` before binding the form. Required fields are guarded by
     * the validation constraints above, not by the constructor signature.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return '' !== $this->name ? $this->name : $this->slug;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

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
