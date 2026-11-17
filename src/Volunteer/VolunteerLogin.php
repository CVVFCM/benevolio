<?php

declare(strict_types=1);

namespace App\Volunteer;

use App\Entity\Person;
use App\Security\VolunteerLinkAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Logs a volunteer in, once the link has been checked.
 *
 * Bumps the login-link version first: every link mailed so far — the one just followed
 * included — stops working. The caller flushes.
 */
final readonly class VolunteerLogin
{
    public const string FIREWALL = 'volunteer';

    public function __construct(
        private Security $security,
    ) {
    }

    public function login(Person $person, bool $rememberMe): void
    {
        $person->bumpLoginLinkVersion();

        $badge = new RememberMeBadge();
        if ($rememberMe) {
            $badge->enable();
        }

        $this->security->login($person, VolunteerLinkAuthenticator::class, self::FIREWALL, [$badge]);
    }
}
