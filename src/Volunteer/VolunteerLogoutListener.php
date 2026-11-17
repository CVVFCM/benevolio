<?php

declare(strict_types=1);

namespace App\Volunteer;

use App\Tenant\UrlPrefixTenantResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function is_string;

/**
 * After « Se déconnecter », back to that association's declaration form.
 */
#[AsEventListener(event: LogoutEvent::class, dispatcher: 'security.event_dispatcher.volunteer')]
final readonly class VolunteerLogoutListener
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $slug = $event->getRequest()->attributes->get(UrlPrefixTenantResolver::ROUTE_ATTRIBUTE);

        if (is_string($slug)) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('declaration', ['organizationSlug' => $slug])));
        }
    }
}
