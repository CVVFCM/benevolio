<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Activity;
use App\Factory\ActivityFactory;
use App\Factory\OrganizationFactory;
use App\Repository\ActivityRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * What the public form offers, and in what order (#44).
 *
 * One association only: the tenant filter is off in a KernelTestCase, and the scoping is
 * OrganizationFilterTest's subject, not this one's.
 */
final class ActivityRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function it_offers_neither_a_retired_activity_nor_one_still_to_come(): void
    {
        $organization = OrganizationFactory::createOne();
        $factory = ActivityFactory::new()->for($organization);
        $factory->event()->dated(new DateTimeImmutable('-10 days'))->create(['name' => 'Passé']);
        $factory->event()->dated(new DateTimeImmutable('today'))->create(['name' => 'Aujourd\'hui']);
        $factory->event()->dated(new DateTimeImmutable('+1 day'))->create(['name' => 'Demain']);
        $factory->action()->inactive()->create(['name' => 'Retirée']);
        $factory->action()->create(['name' => 'Permanente']);

        self::assertSame(['Aujourd\'hui', 'Passé', 'Permanente'], $this->offeredNames());
    }

    #[Test]
    public function events_come_first_then_actions_each_most_recent_first_then_undated_by_name(): void
    {
        $organization = OrganizationFactory::createOne();
        $factory = ActivityFactory::new()->for($organization);
        $factory->action()->create(['name' => 'Entretien']);
        $factory->action()->create(['name' => 'Accueil']);
        $factory->action()->dated(new DateTimeImmutable('-5 days'))->create(['name' => 'Chantier d\'automne']);
        $factory->event()->create(['name' => 'Permanence']);
        $factory->event()->dated(new DateTimeImmutable('-300 days'))->create(['name' => 'Régate']);
        $factory->event()->dated(new DateTimeImmutable('-20 days'))->create(['name' => 'Régate']);

        self::assertSame(
            ['Régate', 'Régate', 'Permanence', 'Chantier d\'automne', 'Accueil', 'Entretien'],
            $this->offeredNames(),
        );

        // Of the two editions, the recent one leads.
        self::assertSame(
            new DateTimeImmutable('-20 days')->format('Y-m-d'),
            ($this->offered()[0] ?? null)?->getBeginsOn()?->format('Y-m-d'),
        );
    }

    /**
     * @return list<string>
     */
    private function offeredNames(): array
    {
        return array_map(static fn (Activity $activity): string => $activity->getName(), $this->offered());
    }

    /**
     * @return list<Activity>
     */
    private function offered(): array
    {
        /** @var list<Activity> $offered */
        $offered = self::getContainer()->get(ActivityRepository::class)
            ->offeredQueryBuilder(new DateTimeImmutable())
            ->getQuery()
            ->getResult();

        return $offered;
    }
}
