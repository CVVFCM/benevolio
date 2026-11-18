<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Activity;
use App\Entity\Organization;
use App\Enum\ActivityType;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Activity>
 */
final class ActivityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Activity::class;
    }

    public function for(Organization $organization): self
    {
        return $this->with(['organization' => $organization]);
    }

    public function event(): self
    {
        return $this->with(['type' => ActivityType::EVENT]);
    }

    public function action(): self
    {
        return $this->with(['type' => ActivityType::ACTION]);
    }

    /** One day when no end is given. */
    public function dated(DateTimeImmutable $beginsOn, ?DateTimeImmutable $endsOn = null): self
    {
        return $this->with(['beginsOn' => $beginsOn, 'endsOn' => $endsOn]);
    }

    /** What the declaration form pre-fills when this activity is chosen (#46). */
    public function withDefaults(?int $days, ?string $workHours): self
    {
        return $this->with(['defaultDays' => $days, 'defaultWorkHours' => $workHours]);
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
            'organization' => OrganizationFactory::new(),
            // Unique: undated activities of one association may not share a name.
            'name' => ucfirst(self::faker()->unique()->sentence(2)),
            'type' => ActivityType::EVENT,
            'beginsOn' => null,
            'endsOn' => null,
            'defaultDays' => null,
            'defaultWorkHours' => null,
            'active' => true,
        ];
    }
}
