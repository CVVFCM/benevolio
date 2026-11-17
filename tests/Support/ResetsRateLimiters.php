<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Empties the rate limiters' storage, so one test's attempts never count against
 * the next — or against the same test an hour later.
 *
 * The limiters keep their counts in a cache pool on disk. That is what makes them
 * hold across requests, in production and in a test client alike (the client reboots
 * the kernel between requests, so anything in memory would be forgotten); it is also
 * what lets them leak between tests. Any test that submits « Mot de passe oublié » or
 * finishes the public form calls this from setUp(), after creating its client.
 */
trait ResetsRateLimiters
{
    private static function resetRateLimiters(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
}
