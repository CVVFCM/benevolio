<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Organization;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Organization>
 */
final class OrganizationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Organization::class;
    }

    public function inactive(): self
    {
        return $this->with(['active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->company(),
            // Slugs must match Organization's regex (lowercase, digits, dashes)
            // and be unique, since they address the public volunteer URLs.
            'slug' => self::faker()->unique()->slug(3),
            'active' => true,
        ];
    }
}
