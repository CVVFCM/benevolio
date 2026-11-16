<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Where a login link lands: on « Choisissez votre mot de passe », and nowhere else
 * until that is done.
 *
 * A login link is only ever sent to give someone a password — an invitation or a
 * forgotten one — so arriving through it means the job is not finished. The session
 * flag is what makes that stick for an account that already HAS a password (the
 * forgotten-password case), where ChoosePasswordEnforcer could not tell from the
 * account alone.
 */
final readonly class LoginLinkSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public const string SESSION_FLAG = 'must_choose_password';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $request->getSession()->set(self::SESSION_FLAG, true);

        return new RedirectResponse($this->urlGenerator->generate('choose_password'));
    }
}
