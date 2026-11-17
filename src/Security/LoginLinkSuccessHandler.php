<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * What happens once a login link has logged someone in.
 *
 * Two things, both about the link being a way to CHOOSE a password and nothing more:
 *
 * - the session is flagged, and App\Security\ChoosePasswordEnforcer keeps it on
 *   « Choisissez votre mot de passe » until one is saved;
 * - every link of that account is invalidated (User::invalidateLoginLinks()), so the
 *   one just used cannot open a second session, and neither can an older one still
 *   sitting in a mailbox. Someone who leaves without choosing a password asks for a
 *   new link through « Mot de passe oublié ».
 */
final readonly class LoginLinkSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public const string SESSION_FLAG = 'must_choose_password';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        if ($user instanceof User) {
            $user->invalidateLoginLinks();
            $this->entityManager->flush();
        }

        $request->getSession()->set(self::SESSION_FLAG, true);

        return new RedirectResponse($this->urlGenerator->generate('choose_password'));
    }
}
