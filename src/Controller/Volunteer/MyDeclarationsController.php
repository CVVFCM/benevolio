<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use App\Exception\UnknownOrganizationException;
use App\Tenant\TenantContext;
use App\Volunteer\CurrentVolunteer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * « Mes déclarations »: the logged-in volunteer's own, newest first, with each line's state.
 */
#[Route(path: '/a/{organizationSlug}/mes-declarations', name: 'volunteer_declarations', methods: [Request::METHOD_GET])]
final readonly class MyDeclarationsController
{
    public function __construct(
        private Environment $twig,
        private TenantContext $tenantContext,
        private CurrentVolunteer $currentVolunteer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $person = $this->currentVolunteer->get();

        if (null === $person) {
            return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $organization->getSlug()]));
        }

        return new Response($this->twig->render('volunteer/declarations.html.twig', [
            'organization' => $organization,
            'person' => $person,
            'declarations' => $person->getDeclarations(),
        ]));
    }
}
