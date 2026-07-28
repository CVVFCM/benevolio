<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

use function is_string;

/**
 * Landing page after a declaration is submitted — "check your inbox", not "done".
 *
 * The URL carries no declaration id, so a shared or bookmarked link cannot expose
 * one volunteer's declaration to another. The address to show comes from the
 * session flash the submitting controller set, which also means a direct visit to
 * this URL shows the generic wording rather than someone else's address.
 */
#[Route(
    path: '/a/{organizationSlug}/declaration/merci',
    name: 'declaration_confirmation',
    methods: [Request::METHOD_GET],
)]
final readonly class DeclarationConfirmationController
{
    /**
     * Flash key carrying the address the confirmation link went to.
     */
    public const string PENDING_EMAIL_FLASH = 'declaration_pending_email';

    public function __construct(
        private Environment $twig,
        private TenantContext $tenantContext,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // See DeclarationController: an unresolved tenant is a 404 here, not a 500.
        $organization = $this->tenantContext->tryGetOrganization();

        if (null === $organization) {
            throw new NotFoundHttpException('Cette association n\'existe pas ou n\'accepte plus de déclarations.');
        }

        return new Response($this->twig->render('public/declaration/confirmation.html.twig', [
            'organization' => $organization,
            'email' => $this->pendingEmail($request),
        ]));
    }

    /**
     * The address the link was sent to, stashed as a flash by the submitting
     * request. A flash rather than a query parameter: an email address in a URL is
     * copied into chat windows, logs and referrers.
     */
    private function pendingEmail(Request $request): string
    {
        $session = $request->getSession();

        if (!$session instanceof Session) {
            return 'votre adresse';
        }

        $flashes = $session->getFlashBag()->get(self::PENDING_EMAIL_FLASH);

        return is_string($flashes[0] ?? null) ? $flashes[0] : 'votre adresse';
    }
}
