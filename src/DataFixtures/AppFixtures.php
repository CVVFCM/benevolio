<?php

declare(strict_types=1);

namespace App\DataFixtures;

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
    }
}
