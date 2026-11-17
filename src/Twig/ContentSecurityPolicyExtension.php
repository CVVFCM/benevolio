<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\ContentSecurityPolicy;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()`: the nonce every <script> printed by a public page must carry, or the
 * policy App\Security\ContentSecurityPolicyListener sends will refuse to run it.
 */
final class ContentSecurityPolicyExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentSecurityPolicy $policy,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->policy->nonce(...)),
        ];
    }
}
