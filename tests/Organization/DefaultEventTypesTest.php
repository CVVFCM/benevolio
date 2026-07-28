<?php

declare(strict_types=1);

namespace App\Tests\Organization;

use App\Entity\EventType;
use App\Entity\Organization;
use App\Factory\OrganizationFactory;
use App\Organization\DefaultEventTypes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;

/**
 * DefaultEventTypes has two explicit call sites rather than being a Doctrine
 * listener, which means a third way of creating an Organization would silently
 * skip it. This test is what makes that omission visible.
 */
final class DefaultEventTypesTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function a_new_organization_starts_with_the_default_types(): void
    {
        $organization = OrganizationFactory::createOne();

        self::assertSame($this->sortedDefaults(), $this->typeNamesOf($organization));
    }

    /**
     * Every type belongs to the organization it was seeded for — otherwise the
     * tenant filter would hide them from the association that needs them.
     */
    #[Test]
    public function each_organization_gets_its_own_types(): void
    {
        $first = OrganizationFactory::createOne(['slug' => 'first']);
        $second = OrganizationFactory::createOne(['slug' => 'second']);

        self::assertSame($this->sortedDefaults(), $this->typeNamesOf($first));
        self::assertSame($this->sortedDefaults(), $this->typeNamesOf($second));

        // Ten rows, not five shared ones. The filter is off already in a
        // KernelTestCase — no request means TenantRequestListener never armed it —
        // so disabling it unconditionally would throw.
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled('organization')) {
            $filters->disable('organization');
        }
        self::assertCount(
            2 * count(DefaultEventTypes::NAMES),
            $this->entityManager->getRepository(EventType::class)->findAll(),
        );
    }

    #[Test]
    public function the_seeded_types_are_all_offered_to_volunteers(): void
    {
        OrganizationFactory::createOne();

        self::assertCount(
            count(DefaultEventTypes::NAMES),
            $this->entityManager->getRepository(EventType::class)->findActive(),
        );
    }

    #[Test]
    public function a_retired_type_is_not_offered_to_volunteers(): void
    {
        $organization = OrganizationFactory::createOne();
        $types = $this->entityManager->getRepository(EventType::class)->findBy(['organization' => $organization]);
        $retired = reset($types);
        self::assertNotFalse($retired);
        $retired->setActive(false);
        $this->entityManager->flush();

        $offered = $this->entityManager->getRepository(EventType::class)->findActive();

        self::assertCount(count(DefaultEventTypes::NAMES) - 1, $offered);
        self::assertNotContains(
            $retired->getName(),
            array_map(static fn (EventType $type): string => $type->getName(), $offered),
        );
    }

    /**
     * @return list<string>
     */
    private function sortedDefaults(): array
    {
        $expected = DefaultEventTypes::NAMES;
        sort($expected);

        return $expected;
    }

    /**
     * Sorted, so the assertion is about which types exist rather than about the
     * order DefaultEventTypes happens to declare them in.
     *
     * @return list<string>
     */
    private function typeNamesOf(Organization $organization): array
    {
        $this->entityManager->clear();

        $names = array_map(
            static fn (EventType $type): string => $type->getName(),
            $this->entityManager->getRepository(EventType::class)->findBy(['organization' => $organization]),
        );
        sort($names);

        return $names;
    }
}
