<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use App\ValueObject\Address;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

use function mb_trim;

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

    /** SIREN is 9 digits, an RNA number is `W` + 9 digits. */
    public const int SIREN_OR_RNA_MAX_LENGTH = 20;
    public const int OBJET_MAX_LENGTH = 500;

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

    /*
     * ---------------------------------------------------------------- CERFA ----
     *
     * What form 2041-RD asks about the beneficiary organisation, and none of which
     * the application needed until it started issuing receipts.
     *
     * All nullable, because every association that already exists predates these
     * columns. A receipt is refused rather than issued incomplete — see
     * App\Receipt\ReceiptEligibility — so "absent" never means "printed blank".
     */

    /**
     * SIREN (9 digits) or RNA (W + 9 digits). **Without it a receipt is not a valid
     * document**, which is why issuance refuses when it is missing rather than
     * leaving the line empty. Mandatory on the form since revision *05.
     */
    #[ORM\Column(length: self::SIREN_OR_RNA_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::SIREN_OR_RNA_MAX_LENGTH)]
    #[Assert\Regex(
        pattern: '/^(\d{9}|W\d{9})$/',
        message: 'Indiquez un SIREN (9 chiffres) ou un numéro RNA (W suivi de 9 chiffres).',
    )]
    private ?string $sirenOrRna = null;

    /*
     * The postal address, as loose columns rather than an #[ORM\Embedded] of
     * App\ValueObject\Address.
     *
     * NOT a preference: Doctrine cannot express a genuinely nullable embeddable. An
     * organisation without an address would hydrate all-null columns into Address's
     * non-nullable typed properties and raise a TypeError. So the parts are stored
     * here and getPostalAddress() builds the value object when they are all present —
     * which keeps Address as the single place that knows how an address validates and
     * how it reads.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $addressNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressStreet = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $addressPostcode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressCity = null;

    /** ISO 3166-1 alpha-2, like Address::$country. */
    #[ORM\Column(length: 2, nullable: true, options: ['fixed' => true])]
    private ?string $addressCountry = null;

    /** The *objet* of the association, as declared. Printed verbatim on the form. */
    #[ORM\Column(length: self::OBJET_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::OBJET_MAX_LENGTH)]
    private ?string $objet = null;

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

    public function getSirenOrRna(): ?string
    {
        return $this->sirenOrRna;
    }

    public function setSirenOrRna(?string $sirenOrRna): self
    {
        $sirenOrRna = null === $sirenOrRna ? null : mb_trim($sirenOrRna);
        $this->sirenOrRna = '' === $sirenOrRna ? null : $sirenOrRna;

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): self
    {
        $this->objet = $objet;

        return $this;
    }

    /**
     * The address as a value object, or null while it is incomplete.
     *
     * Built on demand rather than mapped, for the reason recorded on the columns
     * above. Returns null rather than a half-filled Address: the CERFA either carries
     * a real address or the receipt is not issued.
     */
    public function getPostalAddress(): ?Address
    {
        if (null === $this->addressStreet || null === $this->addressPostcode
            || null === $this->addressCity || null === $this->addressCountry) {
            return null;
        }

        return new Address(
            $this->addressNumber,
            $this->addressStreet,
            $this->addressPostcode,
            $this->addressCity,
            $this->addressCountry,
        );
    }

    public function setPostalAddress(?Address $address): self
    {
        $this->addressNumber = $address?->number;
        $this->addressStreet = $address?->street;
        $this->addressPostcode = $address?->postcode;
        $this->addressCity = $address?->city;
        $this->addressCountry = $address?->country;

        return $this;
    }

    public function getAddressNumber(): ?string
    {
        return $this->addressNumber;
    }

    public function setAddressNumber(?string $addressNumber): self
    {
        $this->addressNumber = $addressNumber;

        return $this;
    }

    public function getAddressStreet(): ?string
    {
        return $this->addressStreet;
    }

    public function setAddressStreet(?string $addressStreet): self
    {
        $this->addressStreet = $addressStreet;

        return $this;
    }

    public function getAddressPostcode(): ?string
    {
        return $this->addressPostcode;
    }

    public function setAddressPostcode(?string $addressPostcode): self
    {
        $this->addressPostcode = $addressPostcode;

        return $this;
    }

    public function getAddressCity(): ?string
    {
        return $this->addressCity;
    }

    public function setAddressCity(?string $addressCity): self
    {
        $this->addressCity = $addressCity;

        return $this;
    }

    public function getAddressCountry(): ?string
    {
        return $this->addressCountry;
    }

    public function setAddressCountry(?string $addressCountry): self
    {
        $this->addressCountry = $addressCountry;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
