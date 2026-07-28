<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Organization;
use App\Entity\User;
use App\Security\Role;
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
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'roles' => [Role::ADMIN->value],
        ];
    }

    protected function initialize(): static
    {
        return $this->withPassword(self::DEFAULT_PASSWORD);
    }
}
