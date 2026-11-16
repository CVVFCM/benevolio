<?php

declare(strict_types=1);

namespace App\Security;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Guards and records every impersonation, on its way in and on its way out.
 *
 * Symfony's switch_user takes `_switch_user` from a query string, a POST body or a
 * header, and checks no CSRF token on any of them: a link on any page a super-admin
 * opens could switch their session. So the switch is only honoured as a POST carrying
 * a token for CSRF_TOKEN_ID — the forms in templates/admin/action/impersonate.html.twig
 * and the banner in templates/bundles/EasyAdminBundle/layout.html.twig. Throwing here
 * aborts the switch before the new token is stored.
 *
 * The log line is the only trace, by decision: who acted as whom, and when. It has its
 * own `impersonation` channel, which production writes straight to the pod's output
 * (config/packages/monolog.yaml) instead of buffering it with everything else until an
 * error; it then lives as long as those logs do.
 */
#[WithMonologChannel('impersonation')]
final readonly class ImpersonationListener
{
    public const string CSRF_TOKEN_ID = 'switch-user';
    public const string CSRF_FIELD = '_csrf_token';

    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: SecurityEvents::SWITCH_USER)]
    public function __invoke(SwitchUserEvent $event): void
    {
        $request = $event->getRequest();

        if (Request::METHOD_POST !== $request->getMethod()
            || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString(self::CSRF_FIELD)))
        ) {
            throw new AccessDeniedException('Switching user needs a POST with a valid CSRF token.');
        }

        $switched = $event->getToken();

        if ($switched instanceof SwitchUserToken) {
            $this->logger->info('Impersonation started: {impersonator} acts as {user}.', [
                'impersonator' => $switched->getOriginalToken()->getUserIdentifier(),
                'user' => $event->getTargetUser()->getUserIdentifier(),
            ]);

            return;
        }

        // On exit the event carries the restored original token; the account being
        // left is still the one in storage.
        $this->logger->info('Impersonation ended: {impersonator} stops acting as {user}.', [
            'impersonator' => $event->getTargetUser()->getUserIdentifier(),
            'user' => $this->tokenStorage->getToken()?->getUserIdentifier(),
        ]);
    }
}
