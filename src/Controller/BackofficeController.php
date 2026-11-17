<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Role;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Where a login lands when nothing asked for a page first: the platform for a
 * super-admin, the association's back office for everyone else.
 *
 * form_login's default target, and where « Choisissez votre mot de passe » sends a saved
 * password: the one place that decides. `/` is the public home page, not for staff. The
 * home page links here too, as « Espace administrateur ».
 */
#[Route(path: '/espace', name: 'backoffice', methods: [Request::METHOD_GET])]
final readonly class BackofficeController
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        // A super-admin has no association, so /admin has nothing to show them.
        $route = $this->authorizationChecker->isGranted(Role::SUPER_ADMIN->value) ? 'platform' : 'admin';

        return new RedirectResponse($this->urlGenerator->generate($route));
    }
}
