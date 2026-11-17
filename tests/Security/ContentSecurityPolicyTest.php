<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Security\ContentSecurityPolicyListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

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
    public static function publicPages(): iterable
    {
        yield 'home' => ['/'];
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
        self::assertStringNotContainsString('data:application/javascript', (string) $this->client->getResponse()->getContent());
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
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());

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
