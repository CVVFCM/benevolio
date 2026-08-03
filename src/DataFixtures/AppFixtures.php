<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Organization;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

use function sprintf;

/**
 * Development dataset for the association the application is being built for.
 *
 * Fixed counts rather than random ones: a reproducible set is worth more than a
 * varied one when the point is reading a list by hand.
 */
final class AppFixtures extends Fixture
{
    public const string ORGANIZATION_NAME = 'Club de Voile des Vieilles-Forges de Charleville-Mézières';
    public const string ORGANIZATION_SLUG = 'cvvfcm';
    public const string ADMIN_EMAIL = 'admin@cvvfcm.test';
    public const string SUPER_ADMIN_EMAIL = 'super-admin@benevolio.test';

    public function load(ObjectManager $manager): void
    {
        // The organization factory seeds the five default tasks with it, so
        // the public form has something to offer straight away.
        // withCerfaIdentity(), or every validated declaration would be refused a receipt
        // for want of a SIREN — the correct behaviour, but not what you want to see first
        // on a fresh database.
        // withSignature() too: an unsigned receipt is valid but has to be signed by hand,
        // and the signed path is the one worth seeing first.
        $organization = OrganizationFactory::new()
            ->withCerfaIdentity()
            ->withSignature()
            ->create([
                'name' => self::ORGANIZATION_NAME,
                'slug' => self::ORGANIZATION_SLUG,
            ]);

        UserFactory::new()
            ->admin($organization)
            ->create(['email' => self::ADMIN_EMAIL]);

        UserFactory::new()
            ->superAdmin()
            ->create(['email' => self::SUPER_ADMIN_EMAIL]);

        // Confirmed declarations: what a treasurer actually works through.
        //
        // The lines need ->confirmed() of their own. The cascade that normally moves
        // them runs when the declaration is confirmed, and here the declaration is
        // already confirmed by the time they exist — so without this they would sit
        // in "en attente de confirmation" under a confirmed declaration, and the
        // guard would make every one of these undecidable.
        foreach ([1, 2, 3] as $lineCount) {
            $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();
            DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->many($lineCount)->create();
        }

        // One with travel in the volunteer's own vehicle, so the fiscal-power path
        // is visible without having to build it by hand.
        $withOwnVehicle = DeclarationFactory::new()->for($organization)->confirmed()->create();
        DeclarationActionFactory::new()
            ->forDeclaration($withOwnVehicle)
            ->confirmed()
            ->withOwnVehicle()
            ->create();

        // And one still waiting on its volunteer, so the back-office shows the
        // "en attente de confirmation" state — and its absent verdict buttons —
        // without anyone having to half-complete the public form first.
        // No ->confirmed() on the lines here, deliberately: they should read
        // "en attente de confirmation" too, in step with their declaration.
        $awaitingConfirmation = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($awaitingConfirmation)->many(2)->create();

        $this->loadFiscalYears($organization);
    }

    /**
     * Two exercices with the real barème, and validated contributions inside them.
     *
     * Both are needed for the ledger page to show anything at all: it lists **validated**
     * lines whose date falls inside the exercice, and nothing above is either. The dates
     * here are explicit, unlike DeclarationActionFactory's defaults, which scatter them
     * relative to *now* — a fixed exercice would otherwise catch a run-dependent subset.
     */
    private function loadFiscalYears(Organization $organization): void
    {
        // 2025 — figures actually in force (CGI annexe IV art. 6 B, arrêté du 27 mars 2023),
        // and CLOSED, so a fresh database can issue that year's receipts straight away. An open
        // exercice refuses the whole run, correctly, but that is not what you want to meet first.
        $closed = FiscalYearFactory::new()
            ->for($organization)
            ->calendarYear(2025)
            ->withPublishedBareme()
            ->closed()
            ->create();

        // 2026 — PROVISIONAL. No arrêté has been published for revenus 2026, and
        // art. 6 B has not been revalorised since March 2023, so these are the 2025
        // figures held over. Check them against the arrêté when one appears.
        //
        // Left OPEN deliberately: it is the year in progress, its rates are still provisional,
        // and it shows both sides of the state — including the refusal a treasurer meets if they
        // try to issue receipts for a year they have not closed.
        $current = FiscalYearFactory::new()
            ->for($organization)
            ->calendarYear(2026)
            ->withPublishedBareme()
            ->create();

        foreach ([$closed, $current] as $fiscalYear) {
            $year = (int) $fiscalYear->getName();

            // Hours only: bénévolat valorisé, débit 864 / crédit 875, never receiptable.
            $hoursOnly = DeclarationFactory::new()->for($organization)->confirmed()->create();
            DeclarationActionFactory::new()
                ->forDeclaration($hoursOnly)
                ->validated()
                ->create([
                    'date' => new DateTimeImmutable(sprintf('%d-03-15', $year)),
                    'workHours' => '7.25',
                    'journeys' => 0,
                    'distanceKm' => 0,
                    'ownVehicle' => false,
                    'fiscalPower' => null,
                ]);

            // Hours and travel in the volunteer's own vehicle: the line that produces
            // both families of entry, and the only one that can lead to a CERFA.
            $withTravel = DeclarationFactory::new()->for($organization)->confirmed()->create();
            DeclarationActionFactory::new()
                ->forDeclaration($withTravel)
                ->validated()
                ->create([
                    'date' => new DateTimeImmutable(sprintf('%d-06-21', $year)),
                    'workHours' => '5.50',
                    'journeys' => 2,
                    'distanceKm' => 34,
                    'ownVehicle' => true,
                    'fiscalPower' => FiscalPower::FIVE_CV,
                ]);
        }
    }
}
