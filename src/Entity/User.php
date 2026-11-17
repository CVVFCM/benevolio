<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InvalidEntityStateException;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Validator\OneOwnerPerOrganization;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
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
#[OneOwnerPerOrganization]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
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

    /**
     * The hashed password — never the plaintext one.
     *
     * Null until the account's holder chooses one: accounts are created without a
     * password and receive a login link instead (App\User\LoginLinkMailer). Null is
     * what the back office shows as « Invitation en attente », and an account in that
     * state cannot log in with a password at all.
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    /**
     * NOT persisted. Carries the plaintext password from a form to the hasher,
     * and is cleared as soon as it has been hashed. Only ever non-null for the
     * duration of one request.
     */
    #[Assert\Length(min: self::PASSWORD_MIN_LENGTH)]
    private ?string $plainPassword = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $loginLinkVersion = 0;

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
    #[Assert\Expression(
        'this.getOrganization() !== null or !this.hasRole(constant("App\\\\Security\\\\Role::OWNER"))',
        message: 'Le responsable d\'une association doit y être rattaché.',
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

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * False while the invitation is pending: the account exists but its holder has
     * not chosen a password yet.
     */
    /**
     * Part of every login link's signature (config/packages/security.yaml), so
     * bumping it invalidates all the links this account was ever sent.
     */
    public function getLoginLinkVersion(): int
    {
        return $this->loginLinkVersion;
    }

    /**
     * Called when a login link is used: that link, and any other still in a mailbox,
     * stop working. A link is a one-time key, not a week-long one.
     */
    public function invalidateLoginLinks(): void
    {
        ++$this->loginLinkVersion;
    }

    /**
     * Whether a session opened as $user may go on being this account.
     *
     * Symfony asks on every request and logs the session out on "no". Its own
     * comparison skips the password when the session's copy has none — which is
     * every session opened by an invitation link. Two people on one invitation
     * (a shared mailbox, a forwarded mail) therefore both stayed logged in after the
     * first chose a password, and the second could then choose another. Comparing
     * the password here, null included, ends every other session the moment one is
     * set or changed.
     *
     * NOT the login-link version: using a link bumps it, and comparing it would log
     * out the very session the link just opened.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $this->id->equals($user->id)
            && $this->email === $user->email
            && $this->password === $user->password
            && $this->getRoles() === $user->getRoles();
    }

    public function hasPassword(): bool
    {
        return null !== $this->password;
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
     * responsible for calling this — see App\Command\CreateUserCommand.
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

    /**
     * The organization's owner — its first admin. See App\User\OwnerAssigner.
     */
    public function isOwner(): bool
    {
        return $this->hasRole(Role::OWNER);
    }

    /**
     * Adds a role to those already held. grant() replaces them, which is right when
     * building an account and wrong when promoting one.
     */
    public function addRole(Role $role): self
    {
        if (!$this->hasRole($role)) {
            $this->roles[] = $role->value;
        }

        return $this;
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
