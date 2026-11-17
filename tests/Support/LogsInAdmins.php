<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Organization;
use App\Entity\User;
use App\Factory\UserFactory;

/**
 * Logs the test client in as a back-office account.
 *
 * The using class holds its client in `$this->client`, created in setUp() before any
 * factory runs — see AGENTS.md, "Create the client before any factory".
 */
trait LogsInAdmins
{
    /**
     * A new admin of the association, logged in. Not its owner: tests that need the
     * owner's extra right — deleting an account — create one and use loginAs().
     */
    private function loginAsAdminOf(Organization $organization): User
    {
        return $this->loginAs(UserFactory::new()->admin($organization)->create());
    }

    /**
     * A new platform super-admin, logged in.
     *
     * @param array<string, mixed> $attributes
     */
    private function loginAsSuperAdmin(array $attributes = []): User
    {
        return $this->loginAs(UserFactory::new()->superAdmin()->create($attributes));
    }

    private function loginAs(User $user): User
    {
        $this->client->loginUser($user);

        return $user;
    }
}
