<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\AdminActionToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

use function sprintf;

/**
 * Reading `/admin` and `/platform` pages: their text, their URLs, and the tokens their
 * buttons carry.
 *
 * The using class holds its client in `$this->client`.
 */
trait ReadsAdminPages
{
    /**
     * The decoded text of the current page. Text, not raw HTML: Twig escapes an
     * apostrophe to &#039;, so a raw-string assertion on a French message fails for the
     * wrong reason.
     */
    private function bodyText(): string
    {
        return $this->client->getCrawler()->filter('body')->text();
    }

    /**
     * GETs a page, insists it answered, and returns its text.
     */
    private function textOf(string $url): string
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        return $this->bodyText();
    }

    /**
     * A request made with an empty EntityManager, so the controller loads what it needs
     * itself instead of being handed an instance a factory left in the identity map.
     *
     * @param array<string, mixed> $parameters
     */
    private function requestFresh(string $method, string $uri, array $parameters = []): Crawler
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $this->client->request($method, $uri, $parameters);
    }

    /**
     * `/admin/declaration/<id>`, `/platform/user/<id>`….
     */
    private static function detailUrl(string $crudPath, Uuid $id): string
    {
        return $crudPath.'/'.$id->toRfc4122();
    }

    /**
     * The token a custom action's button posts, read from the page showing it.
     */
    private function actionTokenOn(string $pageUrl, string $actionUrl): string
    {
        return (string) $this->client->request(Request::METHOD_GET, $pageUrl)
            ->filter(sprintf('form[action="%s"] input[name="%s"]', $actionUrl, AdminActionToken::FIELD))
            ->attr('value');
    }

    /**
     * The token of EasyAdmin's built-in delete, from the confirmation modal the page
     * carries.
     */
    private static function deleteTokenIn(Crawler $page): string
    {
        return (string) $page->filter('#action-confirmation-form input[name="token"]')->attr('value');
    }

    /**
     * A valid token for an action on a row no page offers — another association's, in an
     * isolation test. App\Security\ActionTokenListener refuses a bad token before the
     * action's own tenant check runs, so a test posting a forged one would only prove the
     * listener. Minted in the client's own session, which therefore needs a request made
     * first (the login is not one).
     */
    private function mintActionToken(string $actionName, Uuid $entityId): string
    {
        $request = $this->client->getRequest();
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        try {
            $token = self::getContainer()->get(CsrfTokenManagerInterface::class)
                ->getToken(AdminActionToken::id($actionName, $entityId))
                ->getValue();
        } finally {
            $requestStack->pop();
        }

        $request->getSession()->save();

        return $token;
    }
}
