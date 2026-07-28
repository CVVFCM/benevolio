<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Doctrine\Filter\OrganizationFilter;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\User;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\UserFactory;
use App\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The gate for every tenant-scoped entity: a query on a TenantAware entity cannot
 * see another organization's rows while the filter is armed, and sees everything
 * once it is off (which is how /platform works).
 *
 * Runs against Person and Declaration — the real TenantAware entities. It used to
 * run against a throwaway probe entity, which existed only because the
 * multi-tenant foundation shipped before any business model did.
 */
final class OrganizationFilterTest extends KernelTestCase
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
    public function it_hides_people_belonging_to_another_organization(): void
    {
        [$first, $second] = $this->twoOrganizationsWithOnePersonEach();

        $this->armFilterFor($first);
        $visible = $this->entityManager->getRepository(Person::class)->findAll();

        self::assertCount(1, $visible);
        self::assertSame('first@example.test', $visible[0]->getEmail()->value);

        // Same query, other tenant: the other row and only the other row.
        $this->entityManager->clear();
        $this->armFilterFor($second);
        $visible = $this->entityManager->getRepository(Person::class)->findAll();

        self::assertCount(1, $visible);
        self::assertSame('second@example.test', $visible[0]->getEmail()->value);
    }

    #[Test]
    public function it_hides_another_organizations_person_even_when_fetched_by_id(): void
    {
        [$first, $second] = $this->twoOrganizationsWithOnePersonEach();

        $otherId = $this->entityManager
            ->getRepository(Person::class)
            ->findOneBy(['organization' => $second])
            ?->getId();
        self::assertNotNull($otherId);

        $this->entityManager->clear();
        $this->armFilterFor($first);

        // A direct lookup must not be a way around the filter.
        self::assertNull($this->entityManager->getRepository(Person::class)->find($otherId));
    }

    #[Test]
    public function it_hides_declarations_belonging_to_another_organization(): void
    {
        $first = OrganizationFactory::createOne(['slug' => 'first']);
        $second = OrganizationFactory::createOne(['slug' => 'second']);
        DeclarationFactory::new()->for($first)->create();
        DeclarationFactory::new()->for($second)->create();
        $this->entityManager->clear();

        $this->armFilterFor($first);

        self::assertCount(1, $this->entityManager->getRepository(Declaration::class)->findAll());
    }

    #[Test]
    public function it_returns_every_row_when_the_filter_is_disabled(): void
    {
        $this->twoOrganizationsWithOnePersonEach();

        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(OrganizationFilter::NAME)) {
            $filters->disable(OrganizationFilter::NAME);
        }

        // This is the /platform case: a super-admin resolves to no organization, so
        // the filter is never armed and the platform backoffice sees everything.
        self::assertCount(2, $this->entityManager->getRepository(Person::class)->findAll());
    }

    /**
     * Organization and User must never be filtered: Organization *is* the tenant,
     * and filtering User would make authentication impossible, because the user
     * provider runs before any tenant is known.
     */
    #[Test]
    public function it_never_filters_organization_nor_user(): void
    {
        $first = OrganizationFactory::createOne(['slug' => 'first']);
        $second = OrganizationFactory::createOne(['slug' => 'second']);
        UserFactory::new()->admin($first)->create(['email' => 'first-admin@example.test']);
        UserFactory::new()->admin($second)->create(['email' => 'second-admin@example.test']);

        $this->armFilterFor($first);

        self::assertCount(2, $this->entityManager->getRepository(Organization::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(User::class)->findAll());
    }

    /**
     * DeclarationAction is deliberately NOT TenantAware, so the filter does not
     * touch it. Asserted so the omission stays a recorded decision rather than
     * looking like a bug: the back-office scopes it by hand instead, which
     * tests/Controller/Admin/DeclarationActionIsolationTest covers.
     */
    #[Test]
    public function it_does_not_filter_declaration_actions(): void
    {
        $first = OrganizationFactory::createOne(['slug' => 'first']);
        $second = OrganizationFactory::createOne(['slug' => 'second']);
        DeclarationActionFactory::createOne(['declaration' => DeclarationFactory::new()->for($first)]);
        DeclarationActionFactory::createOne(['declaration' => DeclarationFactory::new()->for($second)]);
        $this->entityManager->clear();

        $this->armFilterFor($first);

        self::assertCount(2, $this->entityManager->getRepository(DeclarationAction::class)->findAll());
    }

    /**
     * @return array{Organization, Organization}
     */
    private function twoOrganizationsWithOnePersonEach(): array
    {
        $first = OrganizationFactory::createOne(['slug' => 'first']);
        $second = OrganizationFactory::createOne(['slug' => 'second']);

        PersonFactory::createOne(['organization' => $first, 'email' => new Email('first@example.test')]);
        PersonFactory::createOne(['organization' => $second, 'email' => new Email('second@example.test')]);
        $this->entityManager->clear();

        return [
            $this->entityManager->getRepository(Organization::class)->find($first->getId()) ?? $first,
            $this->entityManager->getRepository(Organization::class)->find($second->getId()) ?? $second,
        ];
    }

    private function armFilterFor(Organization $organization): void
    {
        $this->entityManager
            ->getFilters()
            ->enable(OrganizationFilter::NAME)
            ->setParameter(OrganizationFilter::PARAMETER, (string) $organization->getId());
    }
}
