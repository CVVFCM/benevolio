<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

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
        // The organization factory seeds the five default event types with it, so
        // the public form has something to offer straight away.
        $organization = OrganizationFactory::createOne([
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
        foreach ([1, 2, 3] as $lineCount) {
            $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();
            DeclarationActionFactory::new()->forDeclaration($declaration)->many($lineCount)->create();
        }

        // One with travel in the volunteer's own vehicle, so the fiscal-power path
        // is visible without having to build it by hand.
        $withOwnVehicle = DeclarationFactory::new()->for($organization)->confirmed()->create();
        DeclarationActionFactory::new()
            ->forDeclaration($withOwnVehicle)
            ->withOwnVehicle()
            ->create();

        // And one still waiting on its volunteer, so the back-office shows the
        // "en attente de confirmation" state — and its absent verdict buttons —
        // without anyone having to half-complete the public form first.
        $awaitingConfirmation = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($awaitingConfirmation)->many(2)->create();
    }
}
