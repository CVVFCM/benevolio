<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Factory\OrganizationFactory;
use App\Security\ContentSecurityPolicyListener;
use App\Tests\Support\LogsInAdmins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function array_filter;
use function array_keys;
use function is_string;
use function json_decode;
use function str_replace;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * The public pages and the login are served with a script policy, and every script
 * they print carries its nonce. A script without one does not run — and nothing but
 * the browser console says so, which is why this is pinned here.
 *
 * The headers Caddy adds on every response (nosniff, Referrer-Policy, the default
 * frame-ancestors) are not visible to a kernel test; they are checked by hand against
 * the running stack.
 */
final class ContentSecurityPolicyTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ResetDatabase;

    /**
     * Stylesheets listed in importmap.php only to be downloaded, then linked by a template.
     * See AGENTS.md, *Guided tours*.
     */
    private const array LINKED_NOT_IMPORTED = ['driver.js/dist/driver.css'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPages(): iterable
    {
        yield 'home' => ['/'];
        // Both carry JSON-LD, a <script> like any other for the policy.
        yield 'guides' => ['/guides'];
        yield 'a guide' => ['/guides/cerfa-11580'];
        yield 'pricing' => ['/gratuit'];
        yield 'login' => ['/login'];
        yield 'forgotten password' => ['/mot-de-passe-oublie'];
        yield 'declaration form' => ['/a/les-jardins/declaration'];
    }

    #[Test]
    #[DataProvider('publicPages')]
    public function a_public_page_allows_only_its_own_nonced_scripts(string $path): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        $policy = (string) $this->client->getResponse()->headers->get(ContentSecurityPolicyListener::HEADER);

        self::assertMatchesRegularExpression("#^script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'$#", $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('data:', $policy);

        preg_match("#'nonce-([^']+)'#", $policy, $matches);
        $nonce = $matches[1] ?? '';

        $scripts = $crawler->filter('script');
        self::assertGreaterThan(0, $scripts->count());
        foreach ($scripts->each(static fn (Crawler $script): ?string => $script->attr('nonce')) as $scriptNonce) {
            self::assertSame($nonce, $scriptNonce, 'A <script> without the nonce would be refused by the browser.');
        }

        // AssetMapper maps a stylesheet imported from JavaScript to a `data:` module,
        // which the policy refuses: the module graph would fail to load. app.css is
        // linked from the template instead.
        //
        // One exception, and only in the import map: every CSS entry of importmap.php is
        // written there as a `data:` URL, whether anything imports it or not. driver.css
        // is listed so that AssetMapper downloads it, and is linked, never imported — the
        // entry is inert. Any other `data:` module, or that one anywhere else, fails here.
        $content = (string) $this->client->getResponse()->getContent();
        $importMap = $crawler->filter('script[type="importmap"]');
        self::assertCount(1, $importMap);
        $map = json_decode($importMap->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        $imports = $map['imports'] ?? null;
        self::assertIsArray($imports);
        $dataModules = array_keys(array_filter($imports, static fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'data:')));
        self::assertSame(self::LINKED_NOT_IMPORTED, $dataModules);
        self::assertStringNotContainsString('data:application/javascript', str_replace($importMap->html(), '', $content));
        self::assertSelectorExists('link[rel="stylesheet"][href*="app"]');
    }

    #[Test]
    public function each_response_gets_its_own_nonce(): void
    {
        $this->client->request(Request::METHOD_GET, '/login');
        $first = $this->client->getResponse()->headers->get(ContentSecurityPolicyListener::HEADER);

        $this->client->request(Request::METHOD_GET, '/login');
        $second = $this->client->getResponse()->headers->get(ContentSecurityPolicyListener::HEADER);

        self::assertNotSame($first, $second);
    }

    /**
     * EasyAdmin's pages are full of inline scripts this project does not control, so
     * a script policy there would break them. They keep Caddy's default only.
     */
    #[Test]
    public function the_back_office_gets_no_script_policy(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAsAdminOf($organization);

        $this->client->request(Request::METHOD_GET, '/admin/declaration');

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader(ContentSecurityPolicyListener::HEADER);
    }

    /**
     * The policy must not cost the home page its public cache: it reads no token and
     * starts no session.
     */
    #[Test]
    public function the_policy_starts_no_session(): void
    {
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseHasHeader(ContentSecurityPolicyListener::HEADER);
        self::assertResponseNotHasCookie('MOCKSESSID');
    }
}
