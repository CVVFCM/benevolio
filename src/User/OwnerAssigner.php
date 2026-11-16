<?php

declare(strict_types=1);

namespace App\User;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Role;

/**
 * Makes the first account of an association its owner.
 *
 * Called explicitly from every place an account is born — the /admin and /platform
 * user CRUDs and `app:user:create` — rather than from a Doctrine listener, for the
 * reason AGENTS.md gives about App\Organization\DefaultTasks: a listener is one
 * place instead of three, but it also fires for fixtures and factories, where an
 * owner appearing out of nowhere would be a surprise. The cost is that a fourth
 * creation path must remember to call this.
 *
 * The owner keeps ROLE_ADMIN alongside ROLE_OWNER: the hierarchy would give it
 * anyway, but anything reading stored roles directly still sees an admin.
 */
final readonly class OwnerAssigner
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function assignIfFirst(User $user): void
    {
        $organization = $user->getOrganization();

        // A platform super-admin belongs to no association, so owns none.
        if (null === $organization || $user->hasRole(Role::SUPER_ADMIN)) {
            return;
        }

        if ($this->users->organizationHasOwner($organization)) {
            return;
        }

        $user->addRole(Role::OWNER);
    }
}
