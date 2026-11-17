<?php

declare(strict_types=1);

namespace App\Security;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * The `volunteer` firewall's authenticator, used only through Security::login().
 *
 * The link is checked by App\Controller\Volunteer\LinkCheckController — signature, expiry,
 * single use — which then logs the volunteer in programmatically. Security::login() needs
 * an authenticator to build the token; this one supports no request on its own, so it can
 * never be reached any other way.
 */
final class VolunteerLinkAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new LogicException('Volunteers are logged in by LinkCheckController through Security::login().');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
