<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * « C'est gratuit »: Benevolio costs nothing, and the two ways to support it — the
 * developer on GitHub Sponsors, the CVVFCM on HelloAsso — are one click away.
 */
final class PricingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_says_benevolio_is_free_and_is_found_by_search_engines(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/gratuit');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=3600, public');
        self::assertResponseNotHasCookie('MOCKSESSID');
        self::assertSelectorTextSame('h1', 'Benevolio est gratuit');
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertSame('http://localhost/gratuit', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    #[Test]
    public function it_links_both_ways_to_support_it(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/gratuit');

        self::assertCount(1, $crawler->filter('.support a[href="https://github.com/sponsors/yohang"]'));
        self::assertCount(1, $crawler->filter('.support a[href="https://www.helloasso.com/associations/cvvfcm/formulaires/4"]'));
        self::assertSelectorTextContains('.support', 'Yohan Giarelli');
    }

    #[Test]
    public function its_structured_data_states_a_free_offer_its_developer_and_its_publisher(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/gratuit');

        $data = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $graph = $data['@graph'] ?? null;
        self::assertIsArray($graph);
        self::assertSame(['Organization', 'Person', 'WebPage'], array_column($graph, '@type'));

        $page = $graph[2] ?? null;
        self::assertIsArray($page);
        $about = $page['about'] ?? null;
        self::assertIsArray($about);
        self::assertSame(['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'], $about['offers'] ?? null);
        self::assertSame(['@id' => 'https://github.com/yohang#person'], $about['author'] ?? null);
    }
}
