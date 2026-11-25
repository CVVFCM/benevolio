<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Organization;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The public demo (#62): one association anyone may try, back office included, with a login
 * printed on /demo and everything put back every night by App\Demo\DemoReset.
 *
 * Which association it is comes from the DEPLOYMENT (`DEMO_ORGANIZATION_SLUG`), never from a
 * flag on the row: a flag is one misclick in /platform away from having a real association
 * wiped at 3:00. Empty disables the demo everywhere — the page, its links, the reset.
 *
 * Being the demo changes two things, and only for that association:
 * - **no mail leaves for it**, whoever would receive it — every mailer asks is() first. Its
 *   login is public, so otherwise our verified sender would mail any address a visitor typed;
 * - so **a declaration filed there is confirmed at once**, there being no link to click, and
 *   the volunteer space, which logs in by a mailed link only, is closed.
 */
final readonly class DemoAssociation
{
    public const string NAME = 'Association de démonstration';

    /**
     * The demo's back-office login, printed on /demo for anyone to use. Given by #62.
     *
     * The address is the platform's own sender. It receives nothing from here: the demo
     * sends no mail at all.
     */
    public const string ADMIN_EMAIL = 'benevolat@cvvfcm.fr';
    public const string ADMIN_PASSWORD = 'demo1234!';

    public function __construct(
        #[Autowire('%app.demo_slug%')]
        private string $slug,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->slug;
    }

    /**
     * The demo's slug; empty when the deployment has no demo.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    public function is(?Organization $organization): bool
    {
        return null !== $organization && $this->isEnabled() && $organization->getSlug() === $this->slug;
    }
}
