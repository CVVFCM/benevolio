<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Doctrine\Filter\OrganizationFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Works out which organization the request belongs to and arms the Doctrine
 * tenant filter accordingly.
 *
 * Runs at priority 4, i.e. after the router (32) so route attributes such as
 * {organizationSlug} are available, and after the firewall (8) so the security
 * token exists for UserTenantResolver.
 *
 * It always sets the filter state — enabled with an id, or disabled. Leaving it
 * untouched would be a cross-tenant leak under FrankenPHP worker mode, where the
 * EntityManager and its filter collection survive between requests.
 */
final readonly class TenantRequestListener
{
    /**
     * @param iterable<TenantResolver> $resolvers ordered by descending priority
     */
    public function __construct(
        #[AutowireIterator('app.tenant_resolver')]
        private iterable $resolvers,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $organization = null;
        foreach ($this->resolvers as $resolver) {
            $organization = $resolver->resolve($event->getRequest());

            if (null !== $organization) {
                break;
            }
        }

        $this->tenantContext->setOrganization($organization);

        $filters = $this->entityManager->getFilters();

        if (null === $organization) {
            // Platform routes and anonymous requests: no tenant scope at all.
            if ($filters->isEnabled(OrganizationFilter::NAME)) {
                $filters->disable(OrganizationFilter::NAME);
            }

            return;
        }

        $filters
            ->enable(OrganizationFilter::NAME)
            ->setParameter(OrganizationFilter::PARAMETER, (string) $organization->getId());
    }
}
