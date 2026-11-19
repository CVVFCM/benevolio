<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Tests\Support\LogsInAdmins;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The association admin's guided tour (#51): on the dashboard, pointing at four menu
 * entries, opening by itself except for a super-admin impersonating the account.
 */
final class DashboardTourTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_dashboard_tours_the_menu_and_opens_by_itself(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $crawler = $this->client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $tour = $this->tour($crawler);
        self::assertSame('true', $tour->attr('data-tour-autostart-value'));

        $selectors = [];
        foreach ($this->steps($tour) as $step) {
            if (isset($step['element'])) {
                $selectors[] = $step['element'];
                self::assertCount(1, $crawler->filter($step['element']), $step['element'].' is not on the dashboard.');
            }
        }
        self::assertSame(
            ['.tour-declarations', '.tour-receipts', '.tour-fiscal-years', '.tour-my-organization', '[data-tour-step="replay"]'],
            $selectors,
        );

        $replay = $tour->filter('[data-tour-step="replay"][data-action="tour#start"]');
        self::assertStringContainsString('Revoir la visite guidée', $replay->text());
        self::assertNotNull($replay->attr('hidden'), 'Without JavaScript the button would do nothing.');
    }

    #[Test]
    public function only_the_dashboard_carries_the_tour(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/declaration');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller~="tour"]'));
    }

    #[Test]
    public function an_impersonating_super_admin_is_not_toured(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'tresorier@jardins.test']);
        $this->loginAsSuperAdmin();

        $crawler = $this->client->request(Request::METHOD_GET, '/platform/user');
        $form = $crawler->filter('input[name="_switch_user"][value="tresorier@jardins.test"]')->closest('form');
        self::assertNotNull($form);
        $this->client->submit($form->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.impersonation-banner');
        self::assertSame('false', $this->tour($crawler)->attr('data-tour-autostart-value'));
    }

    private function tour(Crawler $crawler): Crawler
    {
        $tour = $crawler->filter('[data-controller~="tour"]');
        self::assertCount(1, $tour);
        self::assertSame('admin', $tour->attr('data-tour-id-value'));

        return $tour;
    }

    /**
     * @return list<array{element?: string, title: string, description: string}>
     */
    private function steps(Crawler $tour): array
    {
        /** @var list<array{element?: string, title: string, description: string}> $steps */
        $steps = json_decode((string) $tour->attr('data-tour-steps-value'), true, flags: JSON_THROW_ON_ERROR);

        return $steps;
    }
}
