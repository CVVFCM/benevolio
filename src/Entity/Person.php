<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\EmailType;
use App\Repository\PersonRepository;
use App\Tenant\TenantAware;
use App\Tenant\TenantAwareTrait;
use App\ValueObject\Address;
use App\ValueObject\Email;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

/**
 * Someone who gives to the association — in practice a volunteer.
 *
 * A Person is NOT a User: they have no account and never log in. They identify
 * themselves by filling the first step of the public declaration form, and are
 * matched to an existing record by (organization, email).
 *
 * The address is the person's *current* one, overwritten by each new declaration.
 * If a re-issued tax receipt ever has to carry the address as it was at the time
 * of the donation, that snapshot belongs on Declaration, not here.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'person')]
#[ORM\UniqueConstraint(name: 'uniq_person_organization_email', columns: ['organization_id', 'email'])]
#[UniqueEntity(
    fields: ['organization', 'email'],
    message: 'Une personne avec cette adresse électronique existe déjà dans cette association.',
    errorPath: 'email',
)]
class Person implements TenantAware
{
    use TenantAwareTrait;

    public const int NAME_MAX_LENGTH = 100;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $firstName;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $lastName;

    #[ORM\Column(type: EmailType::NAME, length: Email::MAX_LENGTH)]
    private Email $email;

    #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
    private Address $address;

    /**
     * @var Collection<int, Declaration>
     */
    #[ORM\OneToMany(targetEntity: Declaration::class, mappedBy: 'person')]
    #[ORM\OrderBy(['submittedAt' => 'DESC'])]
    private Collection $declarations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Unlike Organization and User, a Person is never instantiated by EasyAdmin
     * from scratch — they always come from a submitted declaration. So the
     * constructor can require everything that makes a Person meaningful.
     */
    public function __construct(
        Organization $organization,
        string $firstName,
        string $lastName,
        Email $email,
        Address $address,
    ) {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->address = $address;
        $this->declarations = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    /**
     * Called when the same person declares again, possibly having moved.
     */
    public function updateIdentity(string $firstName, string $lastName, Address $address): void
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->address = $address;
    }

    /**
     * @return Collection<int, Declaration>
     */
    public function getDeclarations(): Collection
    {
        return $this->declarations;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
