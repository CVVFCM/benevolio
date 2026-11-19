<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\OrganizationFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * No page is indexed unless it says so (base.html.twig): only the home page and the guides
 * are meant to be found. Forms, logins and the volunteer pages carry noindex without anyone
 * having to remember it. The back offices get theirs from Caddy, which a kernel test does
 * not see.
 */
final class IndexingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privatePages(): iterable
    {
        yield 'login' => ['/login'];
        yield 'forgotten password' => ['/mot-de-passe-oublie'];
        yield 'declaration form' => ['/a/les-jardins/declaration'];
        yield 'volunteer login' => ['/a/les-jardins/connexion'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function indexablePages(): iterable
    {
        yield 'home' => ['/'];
        yield 'guides' => ['/guides'];
        yield 'a guide' => ['/guides/recu-fiscal-abandon-de-frais'];
    }

    #[Test]
    #[DataProvider('privatePages')]
    public function a_page_that_does_not_ask_to_be_indexed_is_not(string $path): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(0, $crawler->filter('link[rel="canonical"]'));
    }

    #[Test]
    #[DataProvider('indexablePages')]
    public function the_presentation_pages_are_indexed(string $path): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertCount(1, $crawler->filter('link[rel="canonical"]'));
    }
}
