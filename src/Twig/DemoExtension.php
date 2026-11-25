<?php

declare(strict_types=1);

namespace App\Twig;

use App\Demo\DemoAssociation;
use App\Entity\Organization;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `demo_slug()`, empty when the deployment has no demo, and `is_demo(organization)`. Neither
 * reads the database nor the token, so the cached public pages may use them.
 */
final class DemoExtension extends AbstractExtension
{
    public function __construct(
        private readonly DemoAssociation $demo,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('demo_slug', $this->demo->getSlug(...)),
            new TwigFunction('is_demo', fn (?Organization $organization): bool => $this->demo->is($organization)),
        ];
    }
}
