<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Doctrine\Filter\OrganizationFilter;
use App\Entity\Organization;
use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Tests\Fixtures\Entity\TenantProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The gate for every future tenant-scoped entity: proves that a query on a
 * TenantAware entity cannot see another organization's rows while the filter is
 * armed, and that it sees everything once the filter is off (which is how
 * /platform works).
 */
final class OrganizationFilterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        // The tenant_probe table is created by Foundry's ResetDatabase trait, which
        // builds the test schema from the mappings — and the probe entity is mapped
        // in the test environment only (see the when@test block in
        // config/packages/doctrine.yaml). Nothing to create here.
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function it_hides_rows_belonging_to_another_organization(): void
    {
        [$first, $second] = $this->twoOrganizationsWithOneProbeEach();

        $this->armFilterFor($first);

        $visible = $this->entityManager->getRepository(TenantProbe::class)->findAll();

        self::assertCount(1, $visible);
        self::assertSame($first->getId()->toRfc4122(), $visible[0]->getOrganization()->getId()->toRfc4122());
        self::assertSame('probe-first', $visible[0]->getLabel());

        // Same query, other tenant: the other row and only the other row.
        $this->entityManager->clear();
        $this->armFilterFor($second);

        $visible = $this->entityManager->getRepository(TenantProbe::class)->findAll();

        self::assertCount(1, $visible);
        self::assertSame('probe-second', $visible[0]->getLabel());
    }

    #[Test]
    public function it_hides_another_organizations_row_even_when_fetched_by_id(): void
    {
        [$first, $second] = $this->twoOrganizationsWithOneProbeEach();

        $otherId = $this->entityManager
            ->getRepository(TenantProbe::class)
            ->findOneBy(['organization' => $second]);
        self::assertNotNull($otherId);
        $otherId = $otherId->getId();

        $this->entityManager->clear();
        $this->armFilterFor($first);

        // A direct lookup must not be a way around the filter.
        self::assertNull(
            $this->entityManager->getRepository(TenantProbe::class)->findOneBy(['id' => $otherId]),
        );
    }

    #[Test]
    public function it_returns_every_row_when_the_filter_is_disabled(): void
    {
        $this->twoOrganizationsWithOneProbeEach();

        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(OrganizationFilter::NAME)) {
            $filters->disable(OrganizationFilter::NAME);
        }

        // This is the /platform case: a super-admin resolves to no organization,
        // so the filter is never armed and the platform backoffice sees everything.
        self::assertCount(2, $this->entityManager->getRepository(TenantProbe::class)->findAll());
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
        UserFactory::new()->admin($first)->create(['email' => 'first@example.test']);
        UserFactory::new()->admin($second)->create(['email' => 'second@example.test']);

        $this->armFilterFor($first);

        self::assertCount(2, $this->entityManager->getRepository(Organization::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(User::class)->findAll());
    }

    /**
     * @return array{Organization, Organization}
     */
    private function twoOrganizationsWithOneProbeEach(): array
    {
        $first = OrganizationFactory::createOne(['slug' => 'first']);
        $second = OrganizationFactory::createOne(['slug' => 'second']);

        $this->entityManager->persist(new TenantProbe($first, 'probe-first'));
        $this->entityManager->persist(new TenantProbe($second, 'probe-second'));
        $this->entityManager->flush();
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
