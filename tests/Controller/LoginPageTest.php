<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\OrganizationFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function sprintf;

/**
 * The login page and the theme switcher it carries.
 *
 * Styling itself is not assertable here — that is what the browser pass in the plan
 * is for. What is assertable is the contract the CSS and the controller depend on:
 * the classes exist, the pre-paint script is present, and the switcher ships in a
 * state that degrades safely.
 */
final class LoginPageTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function it_renders_anonymously(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/login"]'));
        self::assertCount(1, $crawler->filter('input[name="_username"]'));
        self::assertCount(1, $crawler->filter('input[name="_password"]'));
        self::assertCount(1, $crawler->filter('input[name="_csrf_token"]'));
    }

    /**
     * The hooks app.css styles. Asserted because a renamed class would leave the
     * page silently unstyled again, which is the bug this lot fixed.
     */
    #[Test]
    public function it_carries_the_classes_the_stylesheet_targets(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('main.login'));
        self::assertCount(1, $crawler->filter('.login__card'));
        // Reused rather than reinvented: the same controls as the volunteer form.
        self::assertCount(2, $crawler->filter('form input.input'));
        self::assertCount(1, $crawler->filter('button.button'));
    }

    /**
     * Blocking and inline is the whole point: anything deferred runs after first
     * paint, and a visitor who chose dark then sees a white flash on every page.
     */
    #[Test]
    public function the_theme_is_applied_before_first_paint(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        $head = $crawler->filter('head')->html();
        self::assertStringContainsString('benevolio-theme', $head);
        self::assertStringContainsString('dataset.theme', $head);
        self::assertStringNotContainsString('defer', $crawler->filter('head script')->first()->html());
    }

    /**
     * Ships hidden, and the storage key must match the inline script or neither
     * half works.
     */
    #[Test]
    public function the_switcher_offers_three_choices_and_degrades_without_javascript(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        $switcher = $crawler->filter('fieldset.theme');
        self::assertCount(1, $switcher);
        self::assertNotNull($switcher->attr('hidden'));

        foreach (['light', 'dark', 'auto'] as $choice) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('.theme__option[data-theme-value-param="%s"]', $choice)),
                sprintf('The "%s" choice is missing.', $choice),
            );
        }
    }

    #[Test]
    public function the_switcher_is_also_on_the_public_pages(): void
    {
        $client = self::createClient();
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $crawler = $client->request('GET', '/a/'.$organization->getSlug().'/declaration');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('fieldset.theme'));
    }
}
