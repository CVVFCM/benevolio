<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InvalidEntityStateException;
use App\Repository\UserRepository;
use App\Security\Role;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

use function in_array;

/**
 * A back-office account. Volunteers are NOT users: they have no account and
 * identify themselves on the public declaration form with an email one-time
 * code.
 *
 * User is deliberately NOT tenant-aware. The Doctrine tenant filter must never
 * apply to the user provider, because authentication happens before the tenant
 * is known — filtering here would make login impossible. Instead the
 * organization is a plain nullable association, and App\Tenant\UserTenantResolver
 * reads it to arm the filter for the rest of the request.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse électronique est déjà utilisée.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const int EMAIL_MAX_LENGTH = 180;
    public const int PASSWORD_MIN_LENGTH = 8;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::EMAIL_MAX_LENGTH, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: self::EMAIL_MAX_LENGTH)]
    private string $email = '';

    /** The hashed password — never the plaintext one. */
    #[ORM\Column]
    private string $password = '';

    /**
     * NOT persisted. Carries the plaintext password from a form to the hasher,
     * and is cleared as soon as it has been hashed. Only ever non-null for the
     * duration of one request.
     */
    #[Assert\Length(min: self::PASSWORD_MIN_LENGTH)]
    private ?string $plainPassword = null;

    /**
     * Stored as the string values of App\Security\Role. Kept as strings rather
     * than an enum collection because that is what UserInterface::getRoles()
     * and the security layer expect.
     *
     * @var list<string>
     */
    #[ORM\Column]
    #[Assert\Count(min: 1, minMessage: 'Un compte doit avoir au moins un rôle.')]
    #[Assert\All([new Assert\Choice(callback: [Role::class, 'values'])])]
    private array $roles = [];

    /**
     * Null for a platform super-admin, who is not attached to any organization.
     * An organization admin must have one — enforced by the ADMIN_NEEDS_ORGANIZATION
     * expression constraint below rather than by a NOT NULL column, since the
     * same table holds both kinds of account.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Assert\Expression(
        'this.getOrganization() !== null or this.hasRole(constant("App\\\\Security\\\\Role::SUPER_ADMIN"))',
        message: 'Un administrateur d\'association doit être rattaché à une association.',
    )]
    private ?Organization $organization = null;

    /**
     * No-argument constructor: EasyAdmin instantiates entities with
     * `new $fqcn()` before binding the form.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->email;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * The identifier the security layer uses to reload the user from the session.
     *
     * Must be non-empty for the security component to work at all. An empty
     * email means the object was never validated (Assert\NotBlank), so failing
     * here is better than handing the firewall an unusable identifier.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw InvalidEntityStateException::missingProperty($this, 'email');
        }

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Expects an already-hashed password (see UserPasswordHasherInterface).
     */
    public function setPassword(string $hashedPassword): self
    {
        $this->password = $hashedPassword;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = '' === $plainPassword ? null : $plainPassword;

        return $this;
    }

    /**
     * Forgets the plaintext password once it has been hashed. Symfony 8 removed
     * UserInterface::eraseCredentials(), so callers that set a plain password are
     * responsible for calling this — see App\Controller\Platform\UserCrudController.
     */
    public function erasePlainPassword(): void
    {
        $this->plainPassword = null;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        // Every authenticated account gets ROLE_USER, whatever is stored.
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * Takes raw strings because it is the setter EasyAdmin and Symfony forms
     * bind to. Membership of App\Security\Role is enforced by the Assert\Choice
     * constraint on $roles, so invalid input surfaces as a validation error
     * instead of a type error. Prefer grant() from PHP code.
     *
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * Typed alternative to setRoles() for use from PHP (fixtures, factories,
     * services).
     */
    public function grant(Role ...$roles): self
    {
        $this->roles = array_values(array_unique(
            array_map(static fn (Role $role): string => $role->value, $roles),
        ));

        return $this;
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role->value, $this->roles, true);
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): self
    {
        $this->organization = $organization;

        return $this;
    }
}
