<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Activity;
use App\Entity\Organization;
use App\Enum\ActivityType;
use App\Factory\ActivityFactory;
use App\Factory\DeclarationActionFactory;
use App\Factory\OrganizationFactory;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\Tests\Support\ReloadsEntities;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * « Événements et actions » in /admin (#44): an association's own list, the rules on
 * its dates and names, and what the list of declared lines gains from it.
 */
final class ActivityAdminTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReadsAdminPages;
    use ReloadsEntities;
    use ResetDatabase;

    /** The radio values of the type field, in the order of ActivityType's cases. */
    private const array TYPE_RADIOS = ['event' => '0', 'action' => '1'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_list_shows_only_this_associations_activities(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        ActivityFactory::new()->for($mine)->create(['name' => 'Fête des jardins']);
        ActivityFactory::new()->for($theirs)->create(['name' => 'Secret des voiles']);
        $this->loginAsAdminOf($mine);

        $this->client->request(Request::METHOD_GET, '/admin/activity');

        self::assertResponseIsSuccessful();
        $text = $this->bodyText();
        self::assertStringContainsString('Fête des jardins', $text);
        self::assertStringNotContainsString('Secret des voiles', $text);
    }

    #[Test]
    public function another_associations_activity_cannot_be_opened(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = ActivityFactory::new()->for(OrganizationFactory::createOne(['slug' => 'les-voiles']))->create();
        $this->loginAsAdminOf($mine);

        // Fresh: the row just built sits in the shared identity map, where the tenant
        // filter never gets to see a query for it.
        $this->requestFresh(Request::METHOD_GET, '/admin/activity/'.$theirs->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function the_menu_lists_it_after_the_tasks(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $text = $this->textOf('/admin/task');

        $tasks = mb_strpos($text, 'Tâches effectuées');
        $activities = mb_strpos($text, 'Événements et actions');
        self::assertNotFalse($tasks);
        self::assertNotFalse($activities);
        // Right after the tasks.
        self::assertGreaterThan($tasks, $activities);
    }

    #[Test]
    public function an_event_is_created_for_the_admins_association(): void
    {
        $organization = $this->loggedInAssociation();

        $this->create(['name' => 'Régate de printemps', 'type' => 'event', 'beginsOn' => '2026-04-12', 'endsOn' => '2026-04-13']);

        self::assertResponseRedirects();
        $activity = $this->findOne(Activity::class, ['name' => 'Régate de printemps']);
        self::assertSame($organization->getId()->toRfc4122(), $activity->getOrganization()->getId()->toRfc4122());
        self::assertSame(ActivityType::EVENT, $activity->getType());
        self::assertSame('2026-04-12', $activity->getBeginsOn()?->format('Y-m-d'));
        self::assertSame('2026-04-13', $activity->getEndsOn()?->format('Y-m-d'));
        self::assertTrue($activity->isActive());
    }

    #[Test]
    public function an_undated_action_is_accepted(): void
    {
        $this->loggedInAssociation();

        $this->create(['name' => 'Entretien des bateaux', 'type' => 'action']);

        self::assertResponseRedirects();
        $activity = $this->findOne(Activity::class, ['name' => 'Entretien des bateaux']);
        self::assertSame(ActivityType::ACTION, $activity->getType());
        self::assertNull($activity->getBeginsOn());
    }

    #[Test]
    public function the_type_has_no_default_and_is_required(): void
    {
        $this->loggedInAssociation();

        $this->create(['name' => 'Sans type']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('événement ou d\'une action', $this->bodyText());
        self::assertNull($this->findOneOrNull(Activity::class, ['name' => 'Sans type']));
    }

    #[Test]
    public function an_end_date_alone_is_refused(): void
    {
        $this->loggedInAssociation();

        $this->create(['name' => 'Fin seule', 'type' => 'event', 'endsOn' => '2026-04-13']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Indiquez aussi la date de début', $this->bodyText());
    }

    #[Test]
    public function an_end_before_the_begin_is_refused(): void
    {
        $this->loggedInAssociation();

        $this->create(['name' => 'À l\'envers', 'type' => 'event', 'beginsOn' => '2026-04-13', 'endsOn' => '2026-04-12']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('ne peut pas précéder', $this->bodyText());
    }

    #[Test]
    public function the_same_name_is_refused_on_the_same_date_and_accepted_on_another(): void
    {
        $organization = $this->loggedInAssociation();
        ActivityFactory::new()->for($organization)->event()
            ->dated(new DateTimeImmutable('2026-04-12'))
            ->create(['name' => 'Régate']);

        $this->create(['name' => 'Régate', 'type' => 'event', 'beginsOn' => '2026-04-12']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('existe déjà à cette date', $this->bodyText());

        $this->create(['name' => 'Régate', 'type' => 'event', 'beginsOn' => '2027-04-11']);
        self::assertResponseRedirects();
        self::assertCount(2, $this->findAllOf(Activity::class, ['name' => 'Régate']));
    }

    #[Test]
    public function the_same_name_is_refused_twice_undated(): void
    {
        $organization = $this->loggedInAssociation();
        ActivityFactory::new()->for($organization)->action()->create(['name' => 'Entretien']);

        $this->create(['name' => 'Entretien', 'type' => 'action']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(1, $this->findAllOf(Activity::class, ['name' => 'Entretien']));
    }

    #[Test]
    public function another_association_may_use_the_same_name(): void
    {
        ActivityFactory::new()->for(OrganizationFactory::createOne(['slug' => 'les-voiles']))->action()->create(['name' => 'Entretien']);
        $this->loggedInAssociation();

        $this->create(['name' => 'Entretien', 'type' => 'action']);

        self::assertResponseRedirects();
    }

    #[Test]
    public function deleting_an_activity_in_use_is_refused_with_a_readable_message(): void
    {
        $organization = $this->loggedInAssociation();
        $activity = ActivityFactory::new()->for($organization)->create(['name' => 'Régate']);
        DeclarationActionFactory::new()->for($organization)->forActivity($activity)->create();

        $this->delete($activity);

        $text = $this->bodyText();
        self::assertStringContainsString('ne peut pas être supprimé', $text);
        self::assertStringContainsString('Proposé aux bénévoles', $text);
        self::assertNotNull($this->findOneOrNull(Activity::class, ['name' => 'Régate']));
    }

    #[Test]
    public function an_unused_activity_can_be_deleted(): void
    {
        $organization = $this->loggedInAssociation();
        $activity = ActivityFactory::new()->for($organization)->create(['name' => 'Jamais utilisée']);

        $this->delete($activity);

        self::assertNull($this->findOneOrNull(Activity::class, ['name' => 'Jamais utilisée']));
    }

    /**
     * The list of declared lines: a column for the activity beside the free title, a
     * search that matches the activity's name, and a filter on it.
     */
    #[Test]
    public function the_lines_list_shows_searches_and_filters_by_activity(): void
    {
        $organization = $this->loggedInAssociation();
        $regatta = ActivityFactory::new()->for($organization)->create(['name' => 'Régate de printemps']);
        DeclarationActionFactory::new()->for($organization)->forActivity($regatta)->create();
        DeclarationActionFactory::new()->for($organization)->create(['title' => 'Coup de main au hangar']);

        $all = $this->textOf('/admin/declaration-action');
        self::assertStringContainsString('Événement ou action', $all);
        self::assertStringContainsString('Régate de printemps', $all);
        self::assertStringContainsString('Coup de main au hangar', $all);

        $searched = $this->textOf('/admin/declaration-action?query=printemps');
        self::assertStringContainsString('Régate de printemps', $searched);
        self::assertStringNotContainsString('Coup de main au hangar', $searched);

        $filtered = $this->textOf('/admin/declaration-action?'.http_build_query([
            'filters' => ['activity' => ['comparison' => '=', 'value' => $regatta->getId()->toRfc4122()]],
        ]));
        self::assertStringContainsString('Régate de printemps', $filtered);
        self::assertStringNotContainsString('Coup de main au hangar', $filtered);
    }

    /**
     * Read live, by decision: a rename shows on the lines already filed.
     */
    #[Test]
    public function renaming_an_activity_renames_it_on_filed_lines(): void
    {
        $organization = $this->loggedInAssociation();
        $activity = ActivityFactory::new()->for($organization)->create(['name' => 'Ancien nom']);
        $line = DeclarationActionFactory::new()->for($organization)->forActivity($activity)->create();

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/activity/'.$activity->getId()->toRfc4122().'/edit');
        $this->client->submit($crawler->selectButton('Sauvegarder les modifications')->form(['Activity[name]' => 'Nouveau nom']));
        self::assertResponseRedirects();

        $text = $this->textOf('/admin/declaration/'.$line->getDeclaration()->getId()->toRfc4122());
        self::assertStringContainsString('Nouveau nom', $text);
        self::assertStringNotContainsString('Ancien nom', $text);
    }

    private function loggedInAssociation(): Organization
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->loginAsAdminOf($organization);

        return $organization;
    }

    /**
     * Submits « Créer » on the new-activity page.
     *
     * @param array<string, string> $fields
     */
    private function create(array $fields): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/activity/new');
        self::assertResponseIsSuccessful();

        $values = [];
        foreach ($fields as $name => $value) {
            // The type is a pair of radio buttons, which EasyAdmin numbers.
            $values['Activity['.$name.']'] = 'type' === $name ? (self::TYPE_RADIOS[$value] ?? $value) : $value;
        }

        $this->client->submit($crawler->selectButton('Créer')->form($values));
    }

    private function delete(Activity $activity): void
    {
        $url = '/admin/activity/'.$activity->getId()->toRfc4122();
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $this->client->request(Request::METHOD_POST, $url.'/delete', ['token' => self::deleteTokenIn($crawler)]);

        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
    }
}
