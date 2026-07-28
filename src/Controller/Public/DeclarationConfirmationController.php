<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Landing page after a declaration is submitted.
 *
 * Deliberately says nothing about the declaration itself: the URL carries no id,
 * so a shared or bookmarked link cannot expose one volunteer's declaration to
 * another. The acknowledgement email belongs to the CERFA lot.
 */
#[Route(
    path: '/a/{organizationSlug}/declaration/merci',
    name: 'declaration_confirmation',
    methods: [Request::METHOD_GET],
)]
final readonly class DeclarationConfirmationController
{
    public function __construct(
        private Environment $twig,
        private TenantContext $tenantContext,
    ) {
    }

    public function __invoke(): Response
    {
        // See DeclarationController: an unresolved tenant is a 404 here, not a 500.
        $organization = $this->tenantContext->tryGetOrganization();

        if (null === $organization) {
            throw new NotFoundHttpException('Cette association n\'existe pas ou n\'accepte plus de déclarations.');
        }

        return new Response($this->twig->render('public/declaration/confirmation.html.twig', [
            'organization' => $organization,
        ]));
    }
}
