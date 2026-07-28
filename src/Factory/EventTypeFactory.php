<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\EventType;
use App\Entity\Organization;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<EventType>
 */
final class EventTypeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return EventType::class;
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
            // Unique: EventType has a unique index on (organization, name), and a
            // repeated word would collide as soon as a test makes two.
            'name' => ucfirst(self::faker()->unique()->word()),
            'active' => true,
        ];
    }
}
