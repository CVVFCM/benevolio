<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Enum\FiscalPower;

/**
 * The band-1 automobile figures of the barème kilométrique, in millièmes d'euro per
 * kilometre, for the code that seeds an exercice: the dev fixtures and the public demo
 * (App\Demo\DemoReset). Nothing values a contribution from here — an association's rates
 * live on its exercice, where it may correct them.
 *
 * Source: CGI annexe IV art. 6 B, as amended by the **arrêté du 27 mars 2023** (JO du
 * 7 avril 2023). Art. 6 B has not been revalorised since, so the same table applies to
 * revenus 2022 through 2025.
 *
 * Band 1 only — up to 5 000 km. Beyond that the scale switches to a formula with an
 * additive constant keyed to the volunteer's cumulative kilometres **across every
 * association they drive for**, which is not knowable here; see the note on
 * App\Entity\FiscalYear for why nothing in this application tries.
 *
 * NOT the old flat "barème bénévole" (0,324 €/km). That was abolished for revenus 2022 by
 * art. 21 of loi n° 2022-1157, which pointed CGI art. 200, 1 ter at the salaried scale
 * instead. BOFiP BOI-IR-RICI-250-20 still describes the old flat rate and is stale.
 *
 * In production code and not on the Foundry factory, which is a development dependency.
 */
final class PublishedBareme
{
    /**
     * @var array<string, int>
     */
    public const array MILLI_EUROS_PER_KM_2025 = [
        FiscalPower::THREE_CV_OR_LESS->value => 529,
        FiscalPower::FOUR_CV->value => 606,
        FiscalPower::FIVE_CV->value => 636,
        FiscalPower::SIX_CV->value => 665,
        FiscalPower::SEVEN_CV_OR_MORE->value => 697,
    ];
}
