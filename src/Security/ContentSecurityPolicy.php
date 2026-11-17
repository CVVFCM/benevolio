<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

use function sprintf;

/**
 * The script policy of the public pages, and the nonce that goes with it.
 *
 * Scripts only: `script-src`, plus `object-src` and `base-uri`, the two directives a
 * script policy leaks through without. No style or image policy — the stored XSS this
 * answers ran scripts, and a style policy is where a strict header starts breaking
 * pages for nothing it would have stopped.
 *
 * The nonce is one per request, shared by the header and every <script> the page
 * prints (the `csp_nonce()` Twig function). It is request-scoped state in a shared
 * service, so like App\Tenant\TenantContext it is reset between requests: under
 * FrankenPHP worker mode the container outlives the request, and a nonce that
 * survived would be a nonce an attacker could read on one page and use on the next.
 */
final class ContentSecurityPolicy implements ResetInterface
{
    private ?string $nonce = null;

    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(18));
    }

    /**
     * @param list<string> $extraScriptSources hosts allowed beside the page's own
     */
    public function header(array $extraScriptSources = []): string
    {
        return sprintf(
            "script-src 'self' 'nonce-%s'%s; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            $this->nonce(),
            [] === $extraScriptSources ? '' : ' '.implode(' ', $extraScriptSources),
        );
    }

    public function reset(): void
    {
        $this->nonce = null;
    }
}
