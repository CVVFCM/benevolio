<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FiscalYear;
use App\Entity\FiscalYearTaskRate;
use App\Entity\Organization;
use App\Entity\Task;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Entering the per-task and per-bracket rates of an exercice.
 *
 * This form is the ONLY way either kind of rate can be created — there is no CRUD for them, and
 * before it existed they could only be written by fixtures or by hand in SQL. The two things
 * worth holding: a rate can actually be saved, and the task list offers only this association\'s
 * tasks.
 */
final class FiscalYearRatesTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function a_task_rate_can_be_entered_on_the_exercice(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->exercice($organization);
        $task = $this->aTaskOf($organization);
        $this->loginAdminOf($organization);

        $crawler = $this->client->request('GET', $this->editUrl($fiscalYear));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sauvegarder les modifications')->form();
        $name = $form->getName();

        // The row the "add" button would have created client-side. Posting the field names
        // directly is what the browser sends, and it goes through the same binding — including
        // the CSRF token, which getPhpValues() carries.
        /** @var array<string, array<string, mixed>> $values */
        $values = $form->getPhpValues();
        $values[$name]['taskRates'] = [
            ['task' => $task->getId()->toRfc4122(), 'hourlyRateCents' => '18,50'],
        ];

        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $rates = $this->entityManager()->getRepository(FiscalYearTaskRate::class)->findAll();

        self::assertCount(1, $rates);
        self::assertSame(1850, $rates[0]->getHourlyRateCents());
        // equals(), not assertSame(): the manager was cleared, so these are two Uuid objects
        // carrying the same value rather than one shared instance.
        self::assertTrue($task->getId()->equals($rates[0]->getTask()->getId()));
    }

    /**
     * The task list is scoped to the association. FiscalYearTaskRate is deliberately NOT
     * TenantAware, so an unscoped EntityType would offer another association\'s tasks as
     * choices — and picking one would file a rate against a foreign row.
     */
    #[Test]
    public function the_task_list_offers_no_other_associations_tasks(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        $theirTask = $this->aTaskOf($theirs);
        $fiscalYear = $this->exercice($mine);
        $this->loginAdminOf($mine);

        $crawler = $this->client->request('GET', $this->editUrl($fiscalYear));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            $theirTask->getId()->toRfc4122(),
            $crawler->filter('form')->last()->html(),
        );
    }

    /**
     * A rate is readable once entered — the detail page is where a treasurer checks what applies.
     */
    #[Test]
    public function the_detail_page_shows_the_rates(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $fiscalYear = $this->exercice($organization);
        new FiscalYearTaskRate($fiscalYear, $this->aTaskOf($organization))->setHourlyRateCents(1850);
        $this->entityManager()->flush();
        $this->loginAdminOf($organization);

        $this->client->request('GET', '/admin/fiscal-year/'.$fiscalYear->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('18,50', $this->client->getCrawler()->filter('body')->text());
    }

    private function exercice(Organization $organization): FiscalYear
    {
        return FiscalYearFactory::new()->for($organization)->calendarYear(2026)->create();
    }

    /**
     * One of the five tasks every association is seeded with by DefaultTasks — TaskFactory would
     * collide with the unique index on (organization, name).
     */
    private function aTaskOf(Organization $organization): Task
    {
        $task = $this->entityManager()->getRepository(Task::class)
            ->findOneBy(['organization' => $organization, 'name' => 'Arbitrage']);
        self::assertNotNull($task);

        return $task;
    }

    private function editUrl(FiscalYear $fiscalYear): string
    {
        return '/admin/fiscal-year/'.$fiscalYear->getId()->toRfc4122().'/edit';
    }

    private function loginAdminOf(Organization $organization): void
    {
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
