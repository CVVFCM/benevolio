<?php

declare(strict_types=1);

namespace App\Factory;

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
     * The band-1 automobile figures of the barème kilométrique, in millièmes d'euro
     * per kilometre.
     *
     * Source: CGI annexe IV art. 6 B, as amended by the **arrêté du 27 mars 2023**
     * (JO du 7 avril 2023). Art. 6 B has not been revalorised since, so the same table
     * applies to revenus 2022 through 2025.
     *
     * Band 1 only — up to 5 000 km. Beyond that the scale switches to a formula with
     * an additive constant keyed to the volunteer's cumulative kilometres, which this
     * application does not model; see FiscalYear::FIRST_BAND_LIMIT_KM.
     *
     * NOT the old flat "barème bénévole" (0,324 €/km). That was abolished for revenus
     * 2022 by art. 21 of loi n° 2022-1157, which pointed CGI art. 200, 1 ter at the
     * salaried scale instead. BOFiP BOI-IR-RICI-250-20 still describes the old flat
     * rate and is stale.
     *
     * @var array<string, int>
     */
    public const array BAREME_2025_MILLI_EUROS_PER_KM = [
        FiscalPower::THREE_CV_OR_LESS->value => 529,
        FiscalPower::FOUR_CV->value => 606,
        FiscalPower::FIVE_CV->value => 636,
        FiscalPower::SIX_CV->value => 665,
        FiscalPower::SEVEN_CV_OR_MORE->value => 697,
    ];

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
