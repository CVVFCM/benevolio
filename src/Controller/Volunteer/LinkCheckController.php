<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use App\Exception\UnknownOrganizationException;
use App\Repository\PersonRepository;
use App\Tenant\TenantContext;
use App\ValueObject\Email;
use App\Volunteer\VolunteerLink;
use App\Volunteer\VolunteerLogin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Where a volunteer's mailed link lands.
 *
 * The GET logs nobody in: it shows one button, « Continuer », posting the same URL. Mail
 * scanners fetch every link in a message before its reader does, and would otherwise spend
 * a single-use link. The page sends `Referrer-Policy: no-referrer`: its URL is the
 * credential.
 *
 * The POST checks signature, expiry and version, then:
 * - a known volunteer is logged in (the version is bumped: the link is spent);
 * - an address with no Person yet is remembered in the session as proven, and « Mon
 *   profil » creates the Person from it — App\Controller\Volunteer\ProfileController.
 */
#[Route(
    path: '/a/{organizationSlug}/connexion/lien',
    name: 'volunteer_link_check',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class LinkCheckController
{
    /** Session key: the address a first link proved, and the association it proved it for. */
    public const string PROVEN_EMAIL = 'volunteer_proven_email';

    public function __construct(
        private Environment $twig,
        private TenantContext $tenantContext,
        private VolunteerLink $link,
        private PersonRepository $people,
        private VolunteerLogin $login,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $vouched = $this->link->read($request);
        $person = null === $vouched ? null : $this->people->findOneByEmail(new Email($vouched['email']));
        $valid = null !== $vouched && ($person?->getLoginLinkVersion() ?? 0) === $vouched['version'];

        if (!$valid) {
            return $this->noReferrer(new Response(
                $this->twig->render('volunteer/link_invalid.html.twig', ['organization' => $organization]),
                Response::HTTP_GONE,
            ));
        }

        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->noReferrer(new Response(
                $this->twig->render('volunteer/link_check.html.twig', ['organization' => $organization]),
            ));
        }

        if (null === $person) {
            $request->getSession()->set(self::PROVEN_EMAIL, [
                'organization' => $organization->getId()->toRfc4122(),
                'email' => $vouched['email'],
                'rememberMe' => $request->request->getBoolean('_remember_me'),
            ]);

            return new RedirectResponse($this->urlGenerator->generate('volunteer_profile', ['organizationSlug' => $organization->getSlug()]));
        }

        $this->login->login($person, $request->request->getBoolean('_remember_me'));
        $this->entityManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('declaration', ['organizationSlug' => $organization->getSlug()]));
    }

    private function noReferrer(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
