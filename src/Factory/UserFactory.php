<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Organization;
use App\Entity\User;
use App\Security\Role;
use ReflectionProperty;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    /**
     * The password every generated account gets, unless overridden. Fine for
     * fixtures and tests; production accounts are created through the platform
     * backoffice.
     */
    public const string DEFAULT_PASSWORD = 'benevolio-dev-password';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    /**
     * An organization admin: scoped to one association by the tenant filter.
     */
    public function admin(Organization $organization): self
    {
        return $this->with([
            'roles' => [Role::ADMIN->value],
            'organization' => $organization,
        ]);
    }

    /**
     * The association's owner — what App\User\OwnerAssigner makes of its first
     * account. Explicit here: factories bypass the assigner, so an owner only
     * exists in a test that asks for one.
     */
    public function owner(Organization $organization): self
    {
        return $this->with([
            'roles' => [Role::ADMIN->value, Role::OWNER->value],
            'organization' => $organization,
        ]);
    }

    /**
     * An account created but whose holder has not chosen a password yet:
     * « Invitation en attente ».
     *
     * Through reflection because nothing in the application ever clears a password,
     * so User has no method for it — and should not grow one for a test's sake.
     * Registered after initialize()'s default password, so it runs after it.
     */
    public function invited(): self
    {
        return $this->afterInstantiate(
            static function (User $user): void {
                new ReflectionProperty(User::class, 'password')->setValue($user, null);
            },
        );
    }

    /**
     * A platform super-admin: attached to no organization, which is what leaves
     * the tenant filter disabled on /platform.
     */
    public function superAdmin(): self
    {
        return $this->with([
            'roles' => [Role::SUPER_ADMIN->value],
            'organization' => null,
        ]);
    }

    public function withPassword(string $plainPassword): self
    {
        return $this->afterInstantiate(
            function (User $user) use ($plainPassword): void {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // An admin needs an association (User's ADMIN_NEEDS_ORGANIZATION), so the
        // default account is one, with its own. Lazy: admin(), owner() and
        // superAdmin() override it, and then no association is created.
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'roles' => [Role::ADMIN->value],
            'organization' => OrganizationFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this->withPassword(self::DEFAULT_PASSWORD);
    }
}
