<?php

declare(strict_types=1);

namespace App\Tests\Controller\Seo;

use App\Guide\GuideCatalog;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What crawlers ask for at the root: robots.txt, sitemap.xml and llms.txt.
 * Every URL inside is absolute and follows the host the request came on, and each file is
 * cached publicly without waking the firewall.
 */
final class CrawlerFilesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function files(): iterable
    {
        yield 'robots.txt' => ['/robots.txt', 'text/plain; charset=UTF-8'];
        yield 'sitemap.xml' => ['/sitemap.xml', 'application/xml; charset=UTF-8'];
        yield 'llms.txt' => ['/llms.txt', 'text/plain; charset=UTF-8'];
    }

    #[Test]
    #[DataProvider('files')]
    public function each_file_is_public_and_cached(string $path, string $contentType): void
    {
        $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', $contentType);
        self::assertResponseHeaderSame('Cache-Control', 'max-age=86400, public');
        self::assertResponseNotHasCookie('MOCKSESSID');
    }

    #[Test]
    public function robots_keeps_crawlers_out_of_the_back_offices_only(): void
    {
        $this->client->request(Request::METHOD_GET, '/robots.txt', server: ['HTTP_HOST' => 'benevolat.example.org']);

        $robots = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("User-agent: *\nDisallow: /admin\nDisallow: /platform\n", $robots);
        self::assertStringContainsString('Sitemap: http://benevolat.example.org/sitemap.xml', $robots);
        // A crawler must fetch a volunteer page to read its noindex.
        self::assertDoesNotMatchRegularExpression('#^Disallow: /(a/?)?$#m', $robots);
    }

    #[Test]
    public function the_sitemap_lists_the_home_page_and_every_guide(): void
    {
        $this->client->request(Request::METHOD_GET, '/sitemap.xml', server: ['HTTP_HOST' => 'benevolat.example.org']);

        $xml = (string) $this->client->getResponse()->getContent();
        if ('' === $xml) {
            self::fail('The sitemap is empty.');
        }
        $sitemap = new DOMDocument();
        self::assertTrue($sitemap->loadXML($xml));
        $xpath = new DOMXPath($sitemap);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $texts = static function (string $query) use ($xpath): array {
            $values = [];
            $nodes = $xpath->query($query);
            self::assertNotFalse($nodes);
            foreach ($nodes as $node) {
                $values[] = (string) $node->nodeValue;
            }

            return $values;
        };

        $expected = ['http://benevolat.example.org/', 'http://benevolat.example.org/guides', 'http://benevolat.example.org/gratuit'];
        $lastmods = [];
        foreach (new GuideCatalog()->all() as $guide) {
            $expected[] = 'http://benevolat.example.org/guides/'.$guide->slug;
            $lastmods[] = $guide->updatedOn->format('Y-m-d');
        }
        self::assertSame($expected, $texts('//s:url/s:loc'));
        self::assertSame($lastmods, $texts('//s:url/s:lastmod'));
    }

    #[Test]
    public function llms_txt_says_what_benevolio_is_and_links_every_guide(): void
    {
        $this->client->request(Request::METHOD_GET, '/llms.txt', server: ['HTTP_HOST' => 'benevolat.example.org']);

        $llms = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("# Benevolio\n\n> ", $llms);
        self::assertStringContainsString("- [C'est gratuit](<http://benevolat.example.org/gratuit>)", $llms);
        foreach (new GuideCatalog()->all() as $guide) {
            self::assertStringContainsString('- ['.$guide->title.'](<http://benevolat.example.org/guides/'.$guide->slug.'>)', $llms);
        }
    }
}
