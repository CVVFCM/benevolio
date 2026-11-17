<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends the script policy on every page outside the two back offices.
 *
 * The public pages and the login print exactly one inline script (the theme, in
 * base.html.twig) and one import map, both carrying the nonce, so a strict policy
 * costs them nothing. `/admin` and `/platform` are EasyAdmin, whose pages are full
 * of inline scripts this project does not control: they get no script policy, only
 * the `frame-ancestors 'none'` the Caddyfile sets on every response.
 *
 * It reads no security token and starts no session, so the home page stays publicly
 * cacheable — the nonce is cached with the page it belongs to, which is fine: a
 * nonce only has to be unguessable to someone who cannot read the page.
 *
 * Priority 0, before the web profiler's toolbar listener (-128), which knows how to
 * add its own nonces to a policy that is already there.
 */
final readonly class ContentSecurityPolicyListener
{
    public const string HEADER = 'Content-Security-Policy';

    /** Where the dev-only hot-reload scripts in base.html.twig are loaded from. */
    private const string HOT_RELOAD_SOURCE = 'https://cdn.jsdelivr.net';

    private const array BACK_OFFICE_PREFIXES = ['/admin', '/platform'];

    public function __construct(
        private ContentSecurityPolicy $policy,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        foreach (self::BACK_OFFICE_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return;
            }
        }

        $hotReload = null !== $request->server->get('FRANKENPHP_HOT_RELOAD');

        $event->getResponse()->headers->set(
            self::HEADER,
            $this->policy->header($hotReload ? [self::HOT_RELOAD_SOURCE] : []),
        );
    }
}
