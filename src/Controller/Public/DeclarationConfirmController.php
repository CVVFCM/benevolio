<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Declaration\DeclarationConfirmationResult;
use App\Declaration\DeclarationConfirmer;
use App\Exception\UnknownOrganizationException;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The other end of the confirmation email.
 *
 * **The link confirms nothing by being opened.** A GET shows the declaration and one
 * button; the button's POST, to this same address, is what confirms — the arrangement
 * the two login links already have, and for the same reason: mail scanners fetch every
 * address in a message before its reader does. Confirming is also what applies the
 * typed name and address to the volunteer's record, so a GET that confirmed let anyone
 * who knew an email address rewrite the identity printed on that person's next CERFA.
 *
 * Once confirmed, or once expired, the address shows that outcome on a GET. A second
 * visit is deliberately a SUCCESS: volunteers click twice, and treating a repeat as an
 * error would tell people something is broken when nothing is.
 *
 * Does not extend AbstractController, per the project convention.
 */
#[Route(
    path: '/a/{organizationSlug}/declaration/confirmer/{token}',
    name: 'declaration_confirm',
    requirements: ['token' => '[A-Za-z0-9_-]{16,64}'],
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class DeclarationConfirmController
{
    public function __construct(
        private Environment $twig,
        private DeclarationConfirmer $confirmer,
        private TenantContext $tenantContext,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        // See DeclarationController: an unresolved tenant is a 404, not the
        // LogicException TenantContext::getOrganization() would raise.
        $organization = $this->tenantContext->tryGetOrganization();

        if (null === $organization) {
            throw new UnknownOrganizationException();
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            if (null === $this->confirmer->confirm($token)) {
                throw new NotFoundHttpException('Ce lien de confirmation est inconnu.');
            }

            // Back to the GET, which now reads « confirmée »: reloading the page then
            // repeats nothing, and the browser never offers to send the form again.
            return self::credentialPage(new RedirectResponse($request->getUri(), Response::HTTP_SEE_OTHER));
        }

        $found = $this->confirmer->look($token);

        if (null === $found) {
            throw new NotFoundHttpException('Ce lien de confirmation est inconnu.');
        }

        [$declaration, $result] = $found;

        if (null === $result) {
            // Still to confirm. The GET changes NOTHING: a mail scanner opens this
            // address before its reader does, and confirming here would let anyone who
            // knows a volunteer's email have a made-up name and address applied to their
            // record by a machine. The button's POST is what confirms.
            return self::credentialPage(new Response($this->twig->render('public/declaration/confirm.html.twig', [
                'organization' => $organization,
                'declaration' => $declaration,
            ])));
        }

        return self::credentialPage(new Response(
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
        ));
    }

    /**
     * The URL is a credential: keep it out of the Referer of anything this page loads,
     * and out of any cache.
     */
    private static function credentialPage(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
