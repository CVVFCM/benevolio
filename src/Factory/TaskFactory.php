<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Organization;
use App\Entity\Task;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Task>
 */
final class TaskFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Task::class;
    }

    public function for(Organization $organization): self
    {
        return $this->with(['organization' => $organization]);
    }

    public function retired(): self
    {
        return $this->with(['active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'organization' => OrganizationFactory::new(),
            // Unique: Task has a unique index on (organization, name), and a
            // repeated word would collide as soon as a test makes two.
            'name' => ucfirst(self::faker()->unique()->word()),
            'active' => true,
        ];
    }
}
