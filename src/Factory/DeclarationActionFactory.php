<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\DeclarationAction;
use App\Enum\EventType;
use App\Enum\FiscalPower;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function sprintf;

/**
 * @extends PersistentObjectFactory<DeclarationAction>
 */
final class DeclarationActionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return DeclarationAction::class;
    }

    public function withOwnVehicle(FiscalPower $fiscalPower = FiscalPower::FIVE_CV): self
    {
        return $this->with([
            'ownVehicle' => true,
            'fiscalPower' => $fiscalPower,
        ]);
    }

    public function onFoot(): self
    {
        return $this->with([
            'ownVehicle' => false,
            'fiscalPower' => null,
            'journeys' => 0,
            'distanceKm' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'declaration' => DeclarationFactory::new(),
            'eventType' => self::faker()->randomElement(EventType::cases()),
            'title' => self::faker()->sentence(4),
            'description' => self::faker()->optional()->sentence(),
            'date' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'consecutiveDays' => 1,
            // One-way kilometres and the number of one-way journeys: a return trip
            // is two journeys.
            'journeys' => 2,
            'distanceKm' => self::faker()->numberBetween(5, 80),
            'ownVehicle' => false,
            'fiscalPower' => null,
            // Quarter-hour granularity, which is what DECIMAL(5,2) is for.
            'workHours' => sprintf(
                '%d.%02d',
                self::faker()->numberBetween(1, 8),
                self::faker()->numberBetween(0, 3) * 25,
            ),
        ];
    }
}
