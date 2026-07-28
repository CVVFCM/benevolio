<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * Renders the back-office login form.
 *
 * The form is *submitted* to this same route, but this controller never handles
 * the submission: the firewall's form_login authenticator intercepts the POST
 * before routing reaches here (see config/packages/security.yaml). So the only
 * job is rendering, plus surfacing the last error and last identifier.
 *
 * Does not extend AbstractController, per the project convention: collaborators
 * come in through the constructor.
 */
#[Route(
    path: '/login',
    name: 'login',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class LoginController
{
    public function __construct(
        private Environment $twig,
        private AuthenticationUtils $authenticationUtils,
    ) {
    }

    public function __invoke(): Response
    {
        $error = $this->authenticationUtils->getLastAuthenticationError();

        return new Response(
            $this->twig->render('security/login.html.twig', [
                'last_username' => $this->authenticationUtils->getLastUsername(),
                'error' => $error,
            ]),
            // 401 on a failed attempt, so the response status tells the truth
            // instead of returning 200 for a rejected credential. There is no
            // AbstractController to do this for us.
            null === $error ? Response::HTTP_OK : Response::HTTP_UNAUTHORIZED,
        );
    }
}
