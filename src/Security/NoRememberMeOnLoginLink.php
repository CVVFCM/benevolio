<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * A login link never yields a « remember me » cookie.
 *
 * Symfony's login-link authenticator attaches the remember-me badge to every login,
 * and the badge is switched on by a `_remember_me` parameter read from the query
 * string as readily as from a form. So `&_remember_me=1` appended to an emailed link
 * returned a one-week cookie — and a request carrying only that cookie has no session,
 * hence no « must choose a password » flag: whoever held a leaked reset link kept a
 * week of access without changing anything the owner would notice.
 *
 * The box on the password login form is untouched.
 *
 * Priority -40: after CheckRememberMeConditionsListener (-32), which is what enables
 * the badge, and before RememberMeListener (-64), which writes the cookie.
 */
final readonly class NoRememberMeOnLoginLink
{
    #[AsEventListener(event: LoginSuccessEvent::class, priority: -40)]
    public function __invoke(LoginSuccessEvent $event): void
    {
        if ('login_link_check' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $passport = $event->getPassport();

        if ($passport->hasBadge(RememberMeBadge::class)) {
            $passport->getBadge(RememberMeBadge::class)?->disable();
        }
    }
}
