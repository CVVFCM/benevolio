<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Activity;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\Task;
use App\Factory\ActivityFactory;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\FiscalYearTaskRateFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\TaskFactory;
use App\Factory\UserFactory;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\ValueObject\Address;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function sprintf;

/**
 * Nothing a person typed is ever printed as HTML in the association's back office.
 *
 * EasyAdmin prints three things raw, each of which this project fed with typed text:
 * the string a formatValue() callback returns, every flash message, and the dashboard
 * title. A volunteer's address, name or action title — typed on the anonymous public
 * form — therefore ran as script in the treasurer's session; an association's name did
 * the same to anyone opening its back office, a platform super-admin included.
 *
 * So one association is seeded with a marker tag in EVERY free-text field, and every
 * page and every flash is checked for it. The named tests pin the holes that existed;
 * the_marker_is_never_printed_raw is the guard for the next one — a new formatValue()
 * on typed text, or an EasyAdmin upgrade that stops the flash override applying.
 */
final class NoRawUserTextTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReadsAdminPages;
    use ResetDatabase;

    /** Short enough for the tightest column (Address::NUMBER_MAX_LENGTH). */
    private const string MARKER = '<b data-m>';
    private const string ESCAPED = '&lt;b data-m&gt;';

    private KernelBrowser $client;
    private Organization $organization;
    private Person $person;
    private Task $task;
    private Activity $activity;
    private FiscalYear $fiscalYear;
    private Declaration $confirmed;
    private DeclarationAction $line;
    private Declaration $unconfirmed;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $this->organization = OrganizationFactory::createOne([
            'name' => 'Asso '.self::MARKER,
            'objet' => 'Objet '.self::MARKER,
            'addressStreet' => 'rue '.self::MARKER,
            'addressCity' => 'Ville '.self::MARKER,
        ]);
        $this->task = TaskFactory::new()->for($this->organization)->create(['name' => 'Tâche '.self::MARKER]);
        $this->person = PersonFactory::new()->for($this->organization)->create([
            'firstName' => 'Prénom '.self::MARKER,
            'lastName' => 'Nom '.self::MARKER,
            'address' => new Address(self::MARKER, 'rue '.self::MARKER, '08000', 'Ville '.self::MARKER, 'FR'),
        ]);

        // Lines before confirming, or the cascade leaves them unconfirmed.
        $this->confirmed = DeclarationFactory::new()->for($this->organization)->forPerson($this->person)->confirmed()->create();
        $this->line = DeclarationActionFactory::new()->forDeclaration($this->confirmed)->confirmed()->create([
            'task' => $this->task,
            'title' => 'Titre '.self::MARKER,
            'description' => 'Description '.self::MARKER,
        ]);

        // A line named by an activity, whose name is as free as a title.
        $this->activity = ActivityFactory::new()->for($this->organization)->create(['name' => 'Événement '.self::MARKER]);
        DeclarationActionFactory::new()->forDeclaration($this->confirmed)->forActivity($this->activity)->confirmed()->create([
            'task' => $this->task,
        ]);

        $this->unconfirmed = DeclarationFactory::new()->for($this->organization)->forPerson($this->person)->create();
        DeclarationActionFactory::new()->forDeclaration($this->unconfirmed)->create([
            'task' => $this->task,
            'title' => 'Autre '.self::MARKER,
        ]);

        $this->fiscalYear = FiscalYearFactory::new()->for($this->organization)->calendarYear(2025)->create([
            'name' => 'Exercice '.self::MARKER,
        ]);
        FiscalYearTaskRateFactory::new()->forTask($this->fiscalYear, $this->task)->create();

        $this->loginAs(UserFactory::new()->owner($this->organization)->create());
    }

    #[Test]
    public function the_marker_is_never_printed_raw(): void
    {
        $pages = [
            '/admin',
            '/admin/declaration',
            '/admin/declaration/'.$this->confirmed->getId()->toRfc4122(),
            '/admin/declaration/'.$this->unconfirmed->getId()->toRfc4122(),
            '/admin/declaration-action',
            '/admin/declaration-action/'.$this->line->getId()->toRfc4122(),
            '/admin/person',
            '/admin/person/'.$this->person->getId()->toRfc4122(),
            '/admin/activity',
            '/admin/activity/'.$this->activity->getId()->toRfc4122(),
            '/admin/task',
            '/admin/task/'.$this->task->getId()->toRfc4122(),
            '/admin/fiscal-year',
            '/admin/fiscal-year/'.$this->fiscalYear->getId()->toRfc4122(),
            '/admin/fiscal-year/'.$this->fiscalYear->getId()->toRfc4122().'/edit',
            '/admin/fiscal-year/'.$this->fiscalYear->getId()->toRfc4122().'/ledger',
            '/admin/fiscal-year/'.$this->fiscalYear->getId()->toRfc4122().'/ledger/detail',
            '/admin/my-organization',
            '/admin/my-organization/'.$this->organization->getId()->toRfc4122(),
            '/admin/my-organization/'.$this->organization->getId()->toRfc4122().'/edit',
            '/admin/receipt',
            '/admin/receipt/batch/choose-year',
            '/admin/user',
        ];

        $escapedSomewhere = 0;

        foreach ($pages as $page) {
            $this->client->request('GET', $page);

            if ($this->client->getResponse()->isRedirection()) {
                $this->client->followRedirect();
            }

            self::assertResponseIsSuccessful($page);
            $this->assertNoRawMarker($page);
            $escapedSomewhere += (int) str_contains($this->body(), self::ESCAPED);
        }

        // Guards the guard: if the marker stopped reaching the pages at all, the
        // assertions above would pass on nothing.
        self::assertGreaterThan(10, $escapedSomewhere);
    }

    /**
     * The hole an anonymous visitor could reach: the address typed on the public form,
     * returned by a formatValue() callback, which EasyAdmin prints raw.
     */
    #[Test]
    public function a_volunteers_address_is_escaped_on_the_declaration(): void
    {
        $this->client->request('GET', '/admin/declaration/'.$this->confirmed->getId()->toRfc4122());

        $this->assertNoRawMarker('declaration detail');
        self::assertStringContainsString('rue '.self::ESCAPED, $this->body());
    }

    /**
     * The association's name is the dashboard title, printed raw on every page.
     */
    #[Test]
    public function the_associations_name_is_escaped_in_the_dashboard_title(): void
    {
        $this->client->request('GET', '/admin/declaration');

        $this->assertNoRawMarker('dashboard title');
        self::assertStringContainsString('Asso '.self::ESCAPED, $this->body());
    }

    /**
     * A task name set by an admin, inside a formatValue() on the exercice.
     */
    #[Test]
    public function a_task_name_is_escaped_on_the_fiscal_year(): void
    {
        $this->client->request('GET', '/admin/fiscal-year/'.$this->fiscalYear->getId()->toRfc4122());

        $this->assertNoRawMarker('fiscal year detail');
        self::assertStringContainsString('Tâche '.self::ESCAPED, $this->body());
    }

    /**
     * EasyAdmin prints flashes raw; templates/bundles/EasyAdminBundle/flash_messages.html.twig
     * is what escapes them. Each flash below interpolates typed text.
     */
    #[Test]
    public function flashes_carrying_typed_text_are_escaped(): void
    {
        // The volunteer's name: confirming in their stead.
        $crawler = $this->client->request('GET', '/admin/declaration/'.$this->unconfirmed->getId()->toRfc4122());
        $this->submit($crawler, '/confirm-manually');
        $this->assertFlashEscaped('Nom '.self::ESCAPED);

        // The action's title: ruling on one line…
        $lineUrl = '/admin/declaration-action/'.$this->line->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $lineUrl);
        $form = $crawler->filter(sprintf('form[action="%s/validate"]', $lineUrl))->form();
        $this->client->submit($form);
        $this->assertFlashEscaped('Titre '.self::ESCAPED);

        // …and ruling on it again, which a stale tab does.
        $this->client->submit($form);
        $this->assertFlashEscaped('Titre '.self::ESCAPED);

        // The volunteer's name again: the whole-declaration verdict.
        $crawler = $this->client->request('GET', '/admin/declaration/'.$this->unconfirmed->getId()->toRfc4122());
        $this->submit($crawler, '/refuse-all');
        $this->assertFlashEscaped('Nom '.self::ESCAPED);
    }

    /**
     * The task's name, when deleting it is refused because declarations use it.
     */
    #[Test]
    public function the_flash_refusing_to_delete_a_task_is_escaped(): void
    {
        $token = self::deleteTokenIn($this->client->request('GET', '/admin/task'));

        $this->client->request('POST', '/admin/task/'.$this->task->getId()->toRfc4122().'/delete', ['token' => $token]);

        $this->assertFlashEscaped('Tâche '.self::ESCAPED);
    }

    private function submit(Crawler $crawler, string $actionSuffix): void
    {
        $this->client->submit($crawler->filter(sprintf('form[action$="%s"]', $actionSuffix))->form());
    }

    private function assertFlashEscaped(string $expected): void
    {
        self::assertResponseRedirects();
        $this->client->followRedirect();

        $this->assertNoRawMarker('flash');
        self::assertStringContainsString($expected, $this->client->getCrawler()->filter('#flash-messages')->html());
    }

    private function assertNoRawMarker(string $where): void
    {
        self::assertStringNotContainsString(self::MARKER, $this->body(), sprintf('Typed text is printed as HTML on %s.', $where));
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
