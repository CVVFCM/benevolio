<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

use function in_array;

/**
 * Keeps an account on « Choisissez votre mot de passe » until it has chosen one.
 *
 * Two cases, both coming from a login link:
 *   - an invited account has no password at all. Left free to browse, its holder
 *     would work from the link's session and find, next time, that they cannot log
 *     in;
 *   - a forgotten-password link logs in an account that does have one, which only
 *     the session flag set by LoginLinkSuccessHandler can tell apart.
 *
 * Not while a super-admin impersonates the account: they may look at an invited
 * account, but choosing its password is its holder's alone — access_control refuses
 * them the page, so redirecting there would be a loop.
 *
 * Priority 0: after the firewall (8), which is what puts the token in storage.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class ChoosePasswordEnforcer
{
    /** Routes an account must still reach while it has no password. */
    private const array EXEMPT_ROUTES = ['choose_password', '_logout_main', 'login_link_check'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // No session, no logged-in account: return before touching the token,
        // which on a lazy firewall would start authentication — and mark every
        // response private, /health included.
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User || $token instanceof SwitchUserToken) {
            return;
        }

        if (in_array($request->attributes->get('_route'), self::EXEMPT_ROUTES, true)) {
            return;
        }

        $flagged = true === $request->getSession()->get(LoginLinkSuccessHandler::SESSION_FLAG);

        if ($user->hasPassword() && !$flagged) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('choose_password')));
    }
}
