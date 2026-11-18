<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Activity;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Factory\ActivityFactory;
use App\Factory\OrganizationFactory;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #44 on the public form: a line is given for one of the association's events or
 * actions, or — « Autre » — named by a free title.
 */
final class DeclarationActivityTest extends WebTestCase
{
    use Factories;
    use ReloadsEntities;
    use ResetDatabase;
    use ResetsRateLimiters;
    use WalksDeclarationFlow;

    private const string LINE = self::FORM.'[actions][actions][0]';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetRateLimiters();
    }

    #[Test]
    public function the_select_groups_events_and_actions_and_shows_their_dates(): void
    {
        $organization = $this->association();
        ActivityFactory::new()->for($organization)->event()
            ->dated(new DateTimeImmutable('2026-04-12'), new DateTimeImmutable('2026-04-13'))
            ->create(['name' => 'Régate de printemps']);
        ActivityFactory::new()->for($organization)->action()->create(['name' => 'Entretien des bateaux']);

        $select = $this->actionsStep()->filter('[name="'.self::LINE.'[activity]"]');

        self::assertSame('Autre', $select->filter('option[value=""]')->text());
        self::assertSame(['Événements', 'Actions'], $select->filter('optgroup')->each(
            static fn (Crawler $group): string => (string) $group->attr('label'),
        ));
        self::assertSame('Régate de printemps (12–13 avr. 2026)', $select->filter('optgroup[label="Événements"] option')->text());
        self::assertSame('Entretien des bateaux', $select->filter('optgroup[label="Actions"] option')->text());
    }

    #[Test]
    public function an_event_to_come_a_retired_one_and_another_associations_are_not_offered(): void
    {
        $organization = $this->association();
        ActivityFactory::new()->for($organization)->create(['name' => 'Offerte']);
        ActivityFactory::new()->for($organization)->dated(new DateTimeImmutable('+3 days'))->create(['name' => 'À venir']);
        ActivityFactory::new()->for($organization)->inactive()->create(['name' => 'Retirée']);
        ActivityFactory::new()->for(OrganizationFactory::createOne(['slug' => 'les-voiles']))->create(['name' => 'Des voiles']);

        $options = $this->actionsStep()->filter('[name="'.self::LINE.'[activity]"] option')->each(
            static fn (Crawler $option): string => $option->text(),
        );

        self::assertSame(['Autre', 'Offerte'], $options);
    }

    /**
     * A list holding only « Autre » would ask a question with one answer.
     */
    #[Test]
    public function an_association_with_nothing_to_offer_keeps_the_plain_title(): void
    {
        $this->association();

        $crawler = $this->actionsStep();

        self::assertCount(0, $crawler->filter('[name="'.self::LINE.'[activity]"]'));
        self::assertCount(1, $crawler->filter('[name="'.self::LINE.'[title]"]'));
    }

    #[Test]
    public function a_line_given_for_an_activity_is_filed_with_the_link_and_no_title(): void
    {
        $activity = ActivityFactory::new()->for($this->association())->create(['name' => 'Régate de printemps']);

        $this->actionsStep();
        // A title typed beside a chosen activity — no JavaScript hid the field — is dropped.
        $this->submitLine(['activity' => $activity->getId()->toRfc4122(), 'title' => 'Tapé quand même']);
        $this->finish();

        $line = $this->findOne(DeclarationAction::class, []);
        self::assertSame($activity->getId()->toRfc4122(), $line->getActivity()?->getId()->toRfc4122());
        self::assertNull($line->getTitle());
        self::assertSame('Régate de printemps', $line->getLabel());
        // The existing row, not a copy made from the session's detached one.
        self::assertCount(1, $this->findAllOf(Activity::class));

        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertEmailTextBodyContains($email, 'Régate de printemps');
        self::assertEmailHtmlBodyContains($email, 'Régate de printemps');
    }

    #[Test]
    public function autre_keeps_the_free_title(): void
    {
        ActivityFactory::new()->for($this->association())->create(['name' => 'Régate de printemps']);

        $this->actionsStep();
        $this->submitLine(['activity' => '', 'title' => 'Coup de main au hangar']);
        $this->finish();

        $line = $this->findOne(DeclarationAction::class, []);
        self::assertNull($line->getActivity());
        self::assertSame('Coup de main au hangar', $line->getTitle());
        self::assertSame('Coup de main au hangar', $line->getLabel());
    }

    #[Test]
    public function autre_without_a_title_is_refused_on_the_title(): void
    {
        ActivityFactory::new()->for($this->association())->create();

        $this->actionsStep();
        $crawler = $this->submitLine(['activity' => '', 'title' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $link = $crawler->filter('.error-summary a');
        self::assertStringContainsString('ou indiquez un intitulé', $link->text());
        self::assertSame('#'.$crawler->filter('[name="'.self::LINE.'[title]"]')->attr('id'), $link->attr('href'));
    }

    /**
     * The session copy of the draft is detached, as its task is; the select refuses an
     * unmanaged entity.
     */
    #[Test]
    public function going_back_to_the_actions_step_keeps_the_chosen_activity(): void
    {
        $activity = ActivityFactory::new()->for($this->association())->create();

        $this->actionsStep();
        $this->submitLine(['activity' => $activity->getId()->toRfc4122()]);
        self::assertSelectorExists('[name="'.self::FORM.'[legal][accuracyAttested]"]');

        $crawler = $this->client->submitForm('Précédent');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $activity->getId()->toRfc4122(),
            $crawler->filter('[name="'.self::LINE.'[activity]"] option[selected]')->attr('value'),
        );
    }

    /**
     * The row carries what activity_title_controller.js needs, and « Ajouter une action »
     * clones the same markup.
     */
    #[Test]
    public function the_row_is_wired_to_hide_the_title(): void
    {
        ActivityFactory::new()->for($this->association())->create();

        $crawler = $this->actionsStep();

        self::assertCount(1, $crawler->filter('fieldset[data-controller="activity-title"] select[data-activity-title-target="select"][data-action="activity-title#toggle"]'));
        self::assertCount(1, $crawler->filter('fieldset[data-controller="activity-title"] [data-activity-title-target="title"] input'));
        $prototype = (string) $crawler->filter('[data-form-collection-prototype-value]')->attr('data-form-collection-prototype-value');
        self::assertStringContainsString('data-activity-title-target="title"', $prototype);
    }

    private function association(): Organization
    {
        return OrganizationFactory::createOne(['slug' => self::FLOW_SLUG]);
    }

    private function actionsStep(): Crawler
    {
        $this->client->request(Request::METHOD_GET, '/a/'.self::FLOW_SLUG.'/declaration');

        return $this->client->submitForm('Suivant', $this->personStep());
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitLine(array $fields): Crawler
    {
        $values = [
            self::LINE.'[task]' => $this->firstTaskId(),
            self::LINE.'[date]' => new DateTimeImmutable('-30 days')->format('Y-m-d'),
            self::LINE.'[consecutiveDays]' => '1',
            self::LINE.'[workHours]' => '2',
            self::LINE.'[distanceKm]' => '0',
            self::LINE.'[journeys]' => '0',
        ];

        foreach ($fields as $name => $value) {
            $values[self::LINE.'['.$name.']'] = $value;
        }

        return $this->client->submitForm('Suivant', $values);
    }

    private function finish(): void
    {
        $this->client->submitForm('Enregistrer ma déclaration', [
            self::FORM.'[legal][accuracyAttested]' => '1',
            self::FORM.'[legal][expensesWaived]' => '1',
        ]);
        self::assertResponseRedirects();
    }
}
