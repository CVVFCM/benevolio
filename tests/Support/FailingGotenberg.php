<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Receipt\GotenbergClient;
use Symfony\Component\HttpClient\HttpClient;

use function is_string;

/**
 * Stands a Gotenberg that goes down in for the real one.
 *
 * Call it on a freshly booted kernel, before anything has asked the container for a
 * receipt service — a service already built keeps the client it was built with. The
 * rest of the pipeline stays the real one: Twig, qpdf, storage, the database.
 */
trait FailingGotenberg
{
    /**
     * @param int $successes how many documents Gotenberg renders before it stops answering
     */
    private static function gotenbergFailsAfter(int $successes): void
    {
        $dsn = $_SERVER['GOTENBERG_DSN'] ?? $_ENV['GOTENBERG_DSN'] ?? null;
        self::assertTrue(is_string($dsn), 'GOTENBERG_DSN is not set.');

        self::getContainer()->set(
            GotenbergClient::class,
            new GotenbergClient(new FailingHttpClient(HttpClient::create(), $successes), $dsn),
        );
    }
}
