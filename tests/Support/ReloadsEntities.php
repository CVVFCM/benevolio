<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Doctrine\Filter\OrganizationFilter;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

/**
 * Reads back what a test wrote, from the database rather than from memory.
 *
 * Every read here starts by clearing the EntityManager and switching the tenant filter
 * off, and both matter:
 *
 *  - WebTestCase shares its EntityManager with the requests it makes, so an entity
 *    left in the identity map comes back as the same object — stale collections and
 *    all — without any query being made.
 *  - The filter is armed per request. After a request to an association's URL a plain
 *    find() is scoped to that association, and a helper would report "not found" for a
 *    row that is there.
 *
 * For a KernelTestCase or a WebTestCase alike.
 */
trait ReloadsEntities
{
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The EntityManager, emptied and unfiltered: the next read goes to the database and
     * sees every association.
     */
    private function freshEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $filters = $entityManager->getFilters();
        if ($filters->isEnabled(OrganizationFilter::NAME)) {
            $filters->disable(OrganizationFilter::NAME);
        }

        return $entityManager;
    }

    /**
     * The same row, as the database now holds it.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function reload(object $entity): object
    {
        $class = $entity::class;
        $identifier = $this->entityManager()->getClassMetadata($class)->getIdentifierValues($entity);

        $reloaded = $this->freshEntityManager()->find($class, $identifier);
        self::assertNotNull($reloaded, sprintf('%s is no longer in the database.', $class));

        return $reloaded;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return T
     */
    private function findOne(string $class, array $criteria): object
    {
        $found = $this->findOneOrNull($class, $criteria);
        self::assertNotNull($found, sprintf('No %s matches the criteria.', $class));

        return $found;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return T|null
     */
    private function findOneOrNull(string $class, array $criteria): ?object
    {
        return $this->freshEntityManager()->getRepository($class)->findOneBy($criteria);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return list<T>
     */
    private function findAllOf(string $class, array $criteria = []): array
    {
        return $this->freshEntityManager()->getRepository($class)->findBy($criteria);
    }
}
