<?php

declare(strict_types=1);

namespace App\Volunteer;

use App\Entity\Organization;
use App\ValueObject\Email;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_numeric;
use function is_string;

/**
 * The link that logs a volunteer in to one association's space.
 *
 * Not Symfony's login_link: that one signs an existing user, and a first-time volunteer
 * has no Person yet — the link proves an address, and the Person is created from it. So
 * it is a URL signed by UriSigner, carrying the address, the Person's login-link version
 * (0 when there is none) and its expiry. The association is in the path.
 *
 * Single use comes from the version: following a link bumps it (or creates the Person at
 * version 1), so the same URL then carries a version that no longer matches.
 */
final readonly class VolunteerLink
{
    public const string LIFETIME = '+1 hour';

    public function __construct(
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(Organization $organization, Email $email, int $version, DateTimeImmutable $expiresAt): string
    {
        $url = $this->urlGenerator->generate('volunteer_link_check', [
            'organizationSlug' => $organization->getSlug(),
            'email' => $email->value,
            'version' => $version,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, $expiresAt);
    }

    /**
     * What a followed link vouches for, or null when it is unsigned, tampered with or
     * expired. Whether the version still matches is the caller's to check: it needs the
     * Person.
     *
     * @return array{email: string, version: int}|null
     */
    public function read(Request $request): ?array
    {
        try {
            $this->uriSigner->verify($request);
        } catch (SignedUriException) {
            return null;
        }

        $email = $request->query->get('email');
        $version = $request->query->get('version');

        if (!is_string($email) || !is_numeric($version)) {
            return null;
        }

        return ['email' => $email, 'version' => (int) $version];
    }
}
