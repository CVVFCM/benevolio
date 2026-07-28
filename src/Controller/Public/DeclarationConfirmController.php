<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Declaration\DeclarationConfirmationResult;
use App\Declaration\DeclarationConfirmer;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The other end of the confirmation email.
 *
 * Four outcomes, each with its own page. A second click is deliberately a SUCCESS:
 * volunteers click twice, and mail clients prefetch links, so treating a repeat as
 * an error would tell people something is broken when nothing is.
 *
 * Does not extend AbstractController, per the project convention.
 */
#[Route(
    path: '/a/{organizationSlug}/declaration/confirmer/{token}',
    name: 'declaration_confirm',
    requirements: ['token' => '[A-Za-z0-9_-]{16,64}'],
    methods: [Request::METHOD_GET],
)]
final readonly class DeclarationConfirmController
{
    public function __construct(
        private Environment $twig,
        private DeclarationConfirmer $confirmer,
        private TenantContext $tenantContext,
    ) {
    }

    public function __invoke(string $token): Response
    {
        // See DeclarationController: an unresolved tenant is a 404, not the
        // LogicException TenantContext::getOrganization() would raise.
        $organization = $this->tenantContext->tryGetOrganization();

        if (null === $organization) {
            throw new NotFoundHttpException('Cette association n\'existe pas ou n\'accepte plus de déclarations.');
        }

        $outcome = $this->confirmer->confirm($token);

        if (null === $outcome) {
            throw new NotFoundHttpException('Ce lien de confirmation est inconnu.');
        }

        [$declaration, $result] = $outcome;

        return new Response(
            $this->twig->render('public/declaration/confirmed.html.twig', [
                'organization' => $organization,
                'declaration' => $declaration,
                'result' => $result,
            ]),
            // Gone, not OK: the link genuinely no longer works, and saying so
            // honestly is worth more than a reflexive 200 on a page a human reads.
            DeclarationConfirmationResult::EXPIRED === $result
                ? Response::HTTP_GONE
                : Response::HTTP_OK,
        );
    }
}
