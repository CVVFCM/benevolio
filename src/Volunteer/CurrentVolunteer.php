<?php

declare(strict_types=1);

namespace App\Volunteer;

use App\Entity\Person;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The volunteer logged in FOR THIS ASSOCIATION, or nobody.
 *
 * The `volunteer` firewall covers every `/a/{slug}/` URL, so a volunteer logged in for one
 * association still carries that token on another's pages. There they are anonymous: one
 * login is one association. The token is not cleared — that would log them out of the
 * first association too — every page asks this instead. Never read the token directly.
 *
 * It does not wake the lazy firewall for a visitor with no session, so an anonymous page
 * keeps whatever caching it had.
 */
final readonly class CurrentVolunteer
{
    /** Symfony's name for the volunteer firewall's remember-me cookie, see security.yaml. */
    public const string REMEMBER_ME_COOKIE = 'REMEMBERME_VOLUNTEER';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private TenantContext $tenantContext,
        private RequestStack $requestStack,
    ) {
    }

    public function get(): ?Person
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request
            || (!$request->hasPreviousSession() && !$request->cookies->has(self::REMEMBER_ME_COOKIE))) {
            return null;
        }

        $person = $this->tokenStorage->getToken()?->getUser();
        $organization = $this->tenantContext->tryGetOrganization();

        if (!$person instanceof Person || null === $organization) {
            return null;
        }

        return $person->getOrganization()->getId()->equals($organization->getId()) ? $person : null;
    }
}
