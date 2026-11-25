<?php

declare(strict_types=1);

namespace App\Factory;

use App\Accounting\PublishedBareme;
use App\Entity\FiscalYear;
use App\Entity\FiscalYearMileageRate;
use App\Entity\Organization;
use App\Enum\FiscalPower;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function sprintf;

/**
 * @extends PersistentObjectFactory<FiscalYear>
 */
final class FiscalYearFactory extends PersistentObjectFactory
{
    /**
     * The published barème; see App\Accounting\PublishedBareme, where it lives so that
     * production code (the demo) can seed an exercice without this development dependency.
     *
     * @var array<string, int>
     */
    public const array BAREME_2025_MILLI_EUROS_PER_KM = PublishedBareme::MILLI_EUROS_PER_KM_2025;

    public static function class(): string
    {
        return FiscalYear::class;
    }

    public function for(Organization $organization): self
    {
        return $this->with(['organization' => $organization]);
    }

    /**
     * A calendar exercice, named after the year.
     */
    public function calendarYear(int $year): self
    {
        return $this->with([
            'name' => (string) $year,
            'beginsOn' => new DateTimeImmutable(sprintf('%d-01-01', $year)),
            'endsOn' => new DateTimeImmutable(sprintf('%d-12-31', $year)),
        ]);
    }

    /**
     * Gives the exercice the published per-bracket barème rather than one flat
     * default, so a fixture behaves like a real association's books.
     */
    public function withPublishedBareme(): self
    {
        return $this->afterInstantiate(static function (FiscalYear $fiscalYear): void {
            foreach (self::BAREME_2025_MILLI_EUROS_PER_KM as $power => $milliEuros) {
                // The constructor attaches the rate to the exercice, and FiscalYear
                // cascades the persist — so there is nothing to return or collect.
                new FiscalYearMileageRate($fiscalYear, FiscalPower::from($power))
                    ->setMilliEurosPerKm($milliEuros);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // No dates here: FiscalYear's constructor already defaults them to the current
        // calendar year, and naming the year is what callers actually vary.
        return [
            'organization' => OrganizationFactory::new(),
        ];
    }
}
