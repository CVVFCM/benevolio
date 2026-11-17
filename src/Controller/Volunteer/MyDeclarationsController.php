<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use App\Entity\Declaration;
use App\Exception\UnknownOrganizationException;
use App\Repository\DeclarationRepository;
use App\Tenant\TenantContext;
use App\Volunteer\CurrentVolunteer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * « Mes déclarations »: the logged-in volunteer's declarations, one row each, newest first;
 * and each one's page with its lines.
 *
 * Another volunteer's declaration is a 404, the same as one that does not exist: Declaration
 * is TenantAware, so another association's is already invisible, and another volunteer's in
 * the same association must be too.
 */
final readonly class MyDeclarationsController
{
    public function __construct(
        private Environment $twig,
        private TenantContext $tenantContext,
        private CurrentVolunteer $currentVolunteer,
        private DeclarationRepository $declarations,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '/a/{organizationSlug}/mes-declarations', name: 'volunteer_declarations', methods: [Request::METHOD_GET])]
    public function list(): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $person = $this->currentVolunteer->get();

        if (null === $person) {
            return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $organization->getSlug()]));
        }

        return new Response($this->twig->render('volunteer/declarations.html.twig', [
            'organization' => $organization,
            'declarations' => $person->getDeclarations(),
        ]));
    }

    #[Route(path: '/a/{organizationSlug}/mes-declarations/{id}', name: 'volunteer_declaration', methods: [Request::METHOD_GET])]
    public function show(string $id): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $person = $this->currentVolunteer->get();

        if (null === $person) {
            return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $organization->getSlug()]));
        }

        $declaration = Uuid::isValid($id) ? $this->declarations->find(Uuid::fromString($id)) : null;

        if (!$declaration instanceof Declaration || !$declaration->getPerson()->getId()->equals($person->getId())) {
            throw new NotFoundHttpException('Declaration not found.');
        }

        return new Response($this->twig->render('volunteer/declaration.html.twig', [
            'organization' => $organization,
            'declaration' => $declaration,
        ]));
    }
}
