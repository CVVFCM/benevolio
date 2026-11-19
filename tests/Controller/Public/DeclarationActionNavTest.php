<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Factory\ActivityFactory;
use App\Factory\OrganizationFactory;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function array_shift;
use function is_array;
use function str_contains;
use function trim;

/**
 * The action navigator of the actions step (#54): every line by number and name, each a
 * link to its panel, and the lines the server refused marked as such. Rendered by the
 * server, so all of it holds without JavaScript; action_nav_controller.js keeps it live.
 */
final class DeclarationActionNavTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    use ResetsRateLimiters;
    use WalksDeclarationFlow;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetRateLimiters();
    }

    #[Test]
    public function only_the_actions_step_carries_it_and_its_page_is_wider(): void
    {
        OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        self::assertCount(0, $crawler->filter('.action-nav'));
        self::assertCount(0, $crawler->filter('main.public__main--wide'));

        $crawler = $this->client->submitForm('Suivant', $this->personStep());
        self::assertCount(1, $crawler->filter('form.form--with-nav[data-controller~="action-nav"] nav.action-nav'));
        self::assertCount(1, $crawler->filter('main.public__main--wide'));
        self::assertSame(['1 action'], $this->texts($crawler, '.action-nav__count'));

        // Adding a line needs JavaScript: the button ships hidden, the controller shows it.
        self::assertNotNull($crawler->filter('.action-nav__add')->attr('hidden'));

        $this->client->restart();
        $this->reachLegalStep();
        self::assertCount(0, $this->client->getCrawler()->filter('.action-nav'));
    }

    #[Test]
    public function each_line_is_listed_by_name_and_links_to_its_panel(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $activity = ActivityFactory::new()->for($organization)->create(['name' => 'Régate de printemps']);

        $crawler = $this->actionsStep();
        $task = $this->firstTaskId();
        $taskName = trim($crawler->filter('select[name="'.self::FORM.'[actions][actions][0][task]"] option[value="'.$task.'"]')->text());

        $crawler = $this->submitLines([
            ['task' => $task, 'activity' => $activity->getId()->toRfc4122(), 'title' => 'Tapé quand même'],
            ['task' => $task, 'title' => 'Coup de main au hangar'],
            ['task' => $task],
            ['title' => ''],
        ]);

        $links = $crawler->filter('.action-nav__list a');
        self::assertCount(4, $links);

        $names = $this->texts($crawler, '.action-nav__list .action-nav__name');
        // An activity's label carries its dates, when it has some.
        self::assertStringStartsWith('Action 1 : Régate de printemps', array_shift($names) ?? '');
        self::assertSame(
            ['Action 2 : Coup de main au hangar', 'Action 3 : '.$taskName, 'Action 4 : Sans titre'],
            $names,
        );

        $links->each(static function (Crawler $link, int $index) use ($crawler): void {
            $target = (string) $link->attr('href');
            self::assertSame('#'.self::FORM.'_actions_actions_'.$index, $target);
            self::assertCount(1, $crawler->filter('fieldset.action'.$target), $target.' links to no panel.');
        });

        // The controller copies the template; the prototype it clones keeps its placeholder.
        self::assertCount(1, $crawler->filter('template[data-action-nav-target="template"]'));
        self::assertStringContainsString(
            'id="'.self::FORM.'_actions_actions___name__"',
            (string) $crawler->filter('[data-form-collection-prototype-value]')->attr('data-form-collection-prototype-value'),
        );
    }

    #[Test]
    public function the_lines_the_server_refused_are_marked(): void
    {
        OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $this->actionsStep();

        $crawler = $this->submitLines([
            $this->completeLine('Bonne'),
            $this->completeLine('Sans heures', ['workHours' => '']),
            $this->completeLine('Bonne aussi'),
            $this->completeLine('Sans date', ['date' => '']),
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertMarked($crawler, [false, true, false, true]);
        // Without JavaScript the bar counts the lines; the controller then says which one is in view.
        self::assertSame(['4 actions'], $this->texts($crawler, '.action-nav__count'));
        self::assertSame(['2 à corriger'], $this->texts($crawler, '.action-nav__errors'));
    }

    /**
     * A line removed in the browser leaves a gap in the indexes; the collection renumbers
     * the lines and puts their errors on forms it never submitted, which read as valid.
     */
    #[Test]
    public function a_refused_line_is_marked_after_the_lines_were_renumbered(): void
    {
        OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
        $this->actionsStep();

        $crawler = $this->submitLines([
            0 => $this->completeLine('Première'),
            5 => $this->completeLine('Seconde'),
            7 => $this->completeLine('Sans heures', ['workHours' => '']),
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertMarked($crawler, [false, false, true]);
        self::assertSame(['1 à corriger'], $this->texts($crawler, '.action-nav__errors'));
    }

    private function actionsStep(): Crawler
    {
        $this->client->request(Request::METHOD_GET, '/a/'.self::FLOW_SLUG.'/declaration');

        return $this->client->submitForm('Suivant', $this->personStep());
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function completeLine(string $title, array $overrides = []): array
    {
        return [
            'task' => $this->firstTaskId(),
            'title' => $title,
            'date' => new DateTimeImmutable('-30 days')->format('Y-m-d'),
            'consecutiveDays' => '1',
            'workHours' => '2',
            'distanceKm' => '0',
            'journeys' => '0',
            ...$overrides,
        ];
    }

    /**
     * Posts the actions step with these lines, keyed by their index — as the browser does
     * once « Ajouter une action » has cloned rows the crawler's form does not know about.
     *
     * @param array<int, array<string, string>> $lines
     */
    private function submitLines(array $lines): Crawler
    {
        $form = $this->client->getCrawler()->selectButton('Suivant')->form();
        $values = $form->getPhpValues();
        $flow = $values[self::FORM] ?? null;
        self::assertIsArray($flow);
        $actions = $flow['actions'] ?? null;
        self::assertTrue(is_array($actions));

        $actions['actions'] = $lines;
        $flow['actions'] = $actions;
        $values[self::FORM] = $flow;

        return $this->client->request(Request::METHOD_POST, $form->getUri(), $values);
    }

    /**
     * @param list<bool> $expected per line, in order: refused or not
     */
    private function assertMarked(Crawler $crawler, array $expected): void
    {
        self::assertSame($expected, $crawler->filter('.action-nav__list a')->each(
            static fn (Crawler $link): bool => str_contains((string) $link->attr('class'), 'action-nav__link--invalid'),
        ));
        self::assertSame($expected, $crawler->filter('fieldset.action')->each(
            static fn (Crawler $row): bool => null !== $row->attr('data-invalid'),
        ));
        self::assertSame(
            $expected,
            $crawler->filter('.action-nav__list a')->each(
                static fn (Crawler $link): bool => str_contains($link->text(), '(à corriger)'),
            ),
            'The mark is said, not only coloured.',
        );
    }

    /**
     * @return list<string>
     */
    private function texts(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
