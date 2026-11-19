<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Guide\Guide;
use App\Guide\GuideCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function is_array;
use function json_decode;
use function mb_strlen;

use const JSON_THROW_ON_ERROR;

/**
 * The guides under /guides: found by search engines, described for a link preview, with
 * the structured data answer engines read, and cached publicly.
 */
final class GuideTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return iterable<string, array{Guide}>
     */
    public static function guides(): iterable
    {
        foreach (new GuideCatalog()->all() as $guide) {
            yield $guide->slug => [$guide];
        }
    }

    #[Test]
    #[DataProvider('guides')]
    public function a_guide_is_indexable_described_and_sourced(Guide $guide): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/guides/'.$guide->slug);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=3600, public');
        self::assertResponseNotHasCookie('MOCKSESSID');

        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertSelectorTextSame('h1', $guide->title);
        self::assertSame('http://localhost/guides/'.$guide->slug, $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame($guide->description, $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('article', $crawler->filter('meta[property="og:type"]')->attr('content'));
        self::assertLessThanOrEqual(60, mb_strlen($guide->title), 'A search result cuts a longer title.');
        self::assertLessThanOrEqual(155, mb_strlen($guide->description), 'A search result cuts a longer description.');

        self::assertGreaterThan(0, $crawler->filter('.guide__answer p')->count(), 'The short answer comes first.');
        self::assertGreaterThan(0, $crawler->filter('.guide__sources li a[href^="https://"]')->count(), 'Every guide cites its sources.');
        self::assertSame($guide->updatedOn->format('Y-m-d'), $crawler->filter('.guide__date time')->attr('datetime'));

        $graph = $this->graph($crawler);
        $types = array_column($graph, '@type');
        self::assertContains('Article', $types);
        self::assertContains('BreadcrumbList', $types);
        foreach ($graph as $node) {
            if ('Article' === ($node['@type'] ?? null)) {
                self::assertSame($guide->title, $node['headline'] ?? null);
                self::assertSame($guide->updatedOn->format('Y-m-d'), $node['dateModified'] ?? null);
            }
        }
    }

    #[Test]
    public function the_index_lists_every_guide(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/guides');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));

        $links = $crawler->filter('main .guide-list__link')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $expected = [];
        foreach (new GuideCatalog()->all() as $guide) {
            $expected[] = '/guides/'.$guide->slug;
        }
        self::assertSame($expected, $links);
    }

    #[Test]
    public function an_unknown_guide_is_not_found(): void
    {
        $this->client->request(Request::METHOD_GET, '/guides/nulle-part');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graph(Crawler $crawler): array
    {
        $data = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('https://schema.org', $data['@context'] ?? null);
        $graph = $data['@graph'] ?? null;
        self::assertTrue(is_array($graph));

        /** @var list<array<string, mixed>> $graph */
        return $graph;
    }
}
