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
 * Minimal development dataset: one association, one admin for it, and one
 * platform super-admin.
 *
 * Deliberately tiny — there is no domain model yet. Business fixtures
 * (volunteers, missions, contributions) arrive with the entities they populate.
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_ORGANIZATION_SLUG = 'association-demo';
    public const string DEMO_ADMIN_EMAIL = 'admin@association-demo.test';
    public const string DEMO_SUPER_ADMIN_EMAIL = 'super-admin@benevolio.test';

    public function load(ObjectManager $manager): void
    {
        $organization = OrganizationFactory::createOne([
            'name' => 'Association Démo',
            'slug' => self::DEMO_ORGANIZATION_SLUG,
        ]);

        UserFactory::new()
            ->admin($organization)
            ->create(['email' => self::DEMO_ADMIN_EMAIL]);

        UserFactory::new()
            ->superAdmin()
            ->create(['email' => self::DEMO_SUPER_ADMIN_EMAIL]);

        // A handful of submitted declarations to work with in the back-office.
        // Fixed line counts rather than random ones: a reproducible dataset is
        // worth more than a varied one when reading a list by hand.
        foreach ([1, 2, 3] as $lineCount) {
            $declaration = DeclarationFactory::new()->for($organization)->create();
            DeclarationActionFactory::createMany($lineCount, ['declaration' => $declaration]);
        }

        // One with travel in the volunteer's own vehicle, so the fiscal-power path
        // is visible without having to build it by hand.
        $withOwnVehicle = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()
            ->withOwnVehicle()
            ->create(['declaration' => $withOwnVehicle]);
    }
}
