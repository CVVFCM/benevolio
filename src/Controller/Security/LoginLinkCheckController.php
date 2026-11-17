<?php

declare(strict_types=1);

namespace App\Controller\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The page an emailed login link opens: one button, « Continuer ».
 *
 * The link does not log in by being opened. A login link is single-use, and mail
 * scanners (Outlook Safe Links, antivirus gateways) fetch every URL in a message
 * before its reader does: a link consumed by a GET would arrive already dead. So the
 * GET only shows this page, and the button POSTs the link's own parameters back to
 * the same address — `login_link.check_post_only` in config/packages/security.yaml
 * makes the firewall's authenticator intercept that POST, and only that.
 *
 * This controller therefore only ever runs for the GET. Nothing is verified here
 * beyond the expiry, which is readable off the URL and worth saying kindly; the
 * signature is checked by the firewall when the button is pressed.
 */
#[Route(path: '/login/lien', name: 'login_link_check', methods: [Request::METHOD_GET, Request::METHOD_POST])]
final readonly class LoginLinkCheckController
{
    public function __construct(
        private Environment $twig,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->query->getString('user');
        $expires = $request->query->getString('expires');
        $hash = $request->query->getString('hash');

        $usable = '' !== $user && '' !== $hash && ctype_digit($expires)
            && (int) $expires > $this->clock->now()->getTimestamp();

        $response = new Response($this->twig->render('security/login_link.html.twig', [
            'usable' => $usable,
            'user' => $user,
            'expires' => $expires,
            'hash' => $hash,
        ]), $usable ? Response::HTTP_OK : Response::HTTP_GONE);

        // The URL is a credential: keep it out of the Referer of anything this page
        // loads, and out of any cache.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
