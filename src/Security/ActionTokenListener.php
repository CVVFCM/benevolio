<?php

declare(strict_types=1);

namespace App\Security;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function is_array;
use function is_object;
use function is_string;
use function parse_url;

/**
 * Refuses a #[RequiresActionToken] action whose token does not match, before it runs.
 *
 * On kernel.controller_arguments, so the controller and its attributes are known and
 * nothing has happened yet. A refusal is not an error page: the token is most likely from
 * a page left open past the session, so the treasurer is sent back where they clicked —
 * the same-origin Referer, else the CRUD's index — with « La page a expiré ».
 *
 * This runs BEFORE each action's own tenant check. A request for another association's row
 * without a valid token is therefore refused here, and one with a valid token still meets
 * that check; the isolation tests post a real token so they keep proving the second.
 */
final readonly class ActionTokenListener
{
    public const string EXPIRED_MESSAGE = 'La page a expiré. Rechargez-la et recommencez.';

    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if ([] === $event->getAttributes(RequiresActionToken::class)) {
            return;
        }

        $controller = $event->getController();
        if (!is_array($controller) || !is_object($controller[0])) {
            return;
        }

        $request = $event->getRequest();
        $entityId = $request->attributes->get('entityId') ?? $request->query->get('entityId');
        $token = $request->request->getString(AdminActionToken::FIELD);

        if (is_string($entityId) && $this->csrfTokenManager->isTokenValid(
            new CsrfToken(AdminActionToken::id($controller[1], $entityId), $token),
        )) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', self::EXPIRED_MESSAGE);
        }

        $back = $this->sameOriginReferer($request) ?? $this->adminUrlGenerator
            ->unsetAll()
            ->setController($controller[0]::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $event->setController(static fn (): RedirectResponse => new RedirectResponse($back));
    }

    /**
     * The page the button was on, if it is one of ours. A Referer naming another origin
     * is ignored: following it would make this an open redirect.
     */
    private function sameOriginReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (null === $referer) {
            return null;
        }

        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin === $request->getSchemeAndHttpHost() ? $referer : null;
    }
}
