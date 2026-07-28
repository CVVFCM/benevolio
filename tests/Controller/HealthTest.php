<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Kubernetes probes this, so the two ways it could quietly break are what matter:
 * it must answer anonymously — a redirect to /login reads as unhealthy — and it
 * must not depend on a tenant, since it answers before any organization exists.
 */
final class HealthTest extends WebTestCase
{
    #[Test]
    public function it_answers_without_credentials(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * No ResetDatabase, no fixtures: the point is that a brand-new deployment,
     * before anyone has run app:organization:create, still reports healthy.
     */
    #[Test]
    public function it_needs_no_organization_to_exist(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function it_is_never_cached(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health');

        self::assertResponseHeaderSame('Cache-Control', 'max-age=0, private');
    }
}
