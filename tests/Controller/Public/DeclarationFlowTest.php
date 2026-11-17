<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\Task;
use App\Enum\FiscalPower;
use App\Factory\OrganizationFactory;
use App\Form\Declaration\DeclarationDraft;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The public three-step flow, walked end to end.
 *
 * The most valuable assertion here is the validation-group one: FormFlowType
 * validates with ['Default', <current step>], so a constraint accidentally left
 * in the Default group makes step 1 fail on the still-empty step 2 fields. That
 * failure mode is silent and confusing, so it is pinned down explicitly.
 */
final class DeclarationFlowTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReloadsEntities;
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
    public function it_renders_the_three_steps_and_starts_on_the_first(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $crawler = $this->client->request('GET', '/a/les-jardins/declaration');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.steps__item'));
        self::assertSame('Vos coordonnées', $crawler->filter('.steps__item--current .steps__label')->text());
        self::assertSelectorExists('input[name="'.self::FORM.'[person][firstName]"]');
        self::assertSame('les-jardins', $organization->getSlug());
    }

    #[Test]
    public function an_unknown_organization_is_a_404_not_an_error(): void
    {
        $this->client->request('GET', '/a/does-not-exist/declaration');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Deactivating an association must close its public form, not just its
     * back-office.
     */
    #[Test]
    public function an_inactive_organization_is_a_404(): void
    {
        OrganizationFactory::new()->inactive()->create(['slug' => 'fermee']);

        $this->client->request('GET', '/a/fermee/declaration');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * The address names the association, and nothing else may. A logged-in admin
     * used to be handed their own association's form under any unknown or closed
     * address — the account stepped in when the slug resolved nothing — and the
     * declaration was then filed there.
     */
    #[Test]
    #[DataProvider('addressesThatResolveNothing')]
    public function a_logged_in_admin_gets_a_404_too_not_their_own_associations_form(string $slug): void
    {
        OrganizationFactory::new()->inactive()->create(['slug' => 'fermee']);
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->loginAsAdminOf($mine);

        $this->client->request('GET', '/a/'.$slug.'/declaration');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatResolveNothing(): iterable
    {
        yield 'unknown' => ['does-not-exist'];
        yield 'inactive' => ['fermee'];
    }

    /**
     * THE validation-group test. Step 1 must complain about its own invalid email
     * and say NOTHING about the actions or the legal boxes, which belong to steps
     * it has not reached.
     */
    #[Test]
    public function step_one_validates_only_its_own_fields(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');

        $this->client->submitForm('Suivant', [
            self::FORM.'[person][firstName]' => 'Jean',
            self::FORM.'[person][lastName]' => 'Dupont',
            self::FORM.'[person][email]' => 'not-an-email',
            self::FORM.'[person][addressStreet]' => 'rue des Jardins',
            self::FORM.'[person][addressPostcode]' => '44000',
            self::FORM.'[person][addressCity]' => 'Nantes',
        ]);

        // A rejected step must not answer 200.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->assertPageContainsText('Cette adresse électronique n\'est pas valide.');
        $this->assertPageDoesNotContainText('Ajoutez au moins une action bénévole.');
        $this->assertPageDoesNotContainText('Vous devez attester');
        // Still on step 1.
        self::assertSelectorExists('input[name="'.self::FORM.'[person][firstName]"]');
    }

    #[Test]
    public function a_french_postcode_must_have_five_digits(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');

        $this->client->submitForm('Suivant', $this->personStep(['addressPostcode' => '440']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertPageContainsText('Un code postal français comporte 5 chiffres.');
    }

    #[Test]
    public function it_walks_the_whole_flow_and_persists_the_declaration(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $this->completeFlow();

        self::assertResponseRedirects('/a/les-jardins/declaration/merci');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        // Not "enregistrée": the declaration exists but does nothing until the
        // volunteer opens the emailed link.
        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
        $this->assertPageContainsText('jean.dupont@example.test');

        $entityManager = $this->freshEntityManager();
        $people = $entityManager->getRepository(Person::class)->findAll();
        self::assertCount(1, $people);
        $person = reset($people);

        self::assertSame('Jean Dupont', $person->getFullName());
        // Normalised by the Email value object on the way in.
        self::assertSame('jean.dupont@example.test', $person->getEmail()->value);
        self::assertSame('rue des Jardins', $person->getAddress()->street);
        self::assertSame($organization->getId()->toRfc4122(), $person->getOrganization()->getId()->toRfc4122());

        $declarations = $entityManager->getRepository(Declaration::class)->findAll();
        self::assertCount(1, $declarations);
        $declaration = $declarations[0];

        self::assertSame(DeclarationState::AWAITING_CONFIRMATION, $declaration->getState());
        self::assertFalse($declaration->isConfirmed());
        self::assertTrue($declaration->isAccuracyAttested());
        self::assertTrue($declaration->isExpensesWaived());
        self::assertCount(1, $declaration->getActions());

        $action = $declaration->getActions()->first();
        self::assertNotFalse($action);
        // In step with the declaration above, which is the point: the line used to
        // read SUBMITTED while the declaration it belonged to was still awaiting
        // the volunteer's click.
        self::assertSame(DeclarationActionState::AWAITING_CONFIRMATION, $action->getState());
        self::assertSame('Régate du printemps', $action->getTitle());
        // "6.5" typed, stored with the two decimals the column holds.
        self::assertSame('6.50', $action->getWorkHours());
        // One-way distance × journeys.
        self::assertSame(24, $action->getTotalDistanceKm());
    }

    /**
     * The task is a Doctrine entity carried inside the session-stored draft,
     * which SessionDataStorage deep-clones — detaching it. DeclarationSubmitter
     * re-fetches it for that reason; without that the submission dies with
     * "A new entity was found through the relationship". This asserts the action
     * ends up pointing at the *existing* row rather than a duplicate.
     */
    #[Test]
    public function the_chosen_task_survives_the_session_round_trip(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $typesBefore = $this->freshEntityManager()->getRepository(Task::class)->count(['organization' => $organization]);

        $this->completeFlow();
        $this->client->followRedirect();

        $entityManager = $this->freshEntityManager();
        // No stray copy of the type was created by the round trip.
        self::assertSame(
            $typesBefore,
            $entityManager->getRepository(Task::class)->count(['organization' => $organization]),
        );

        $actions = $entityManager->getRepository(DeclarationAction::class)->findAll();
        self::assertCount(1, $actions);
        $task = reset($actions)->getTask();
        // And it belongs to this association, not to a detached orphan.
        self::assertSame(
            $organization->getId()->toRfc4122(),
            $task->getOrganization()->getId()->toRfc4122(),
        );
    }

    #[Test]
    public function the_legal_step_refuses_an_unticked_box(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->reachLegalStep();

        $this->client->submitForm('Enregistrer ma déclaration', [
            self::FORM.'[legal][accuracyAttested]' => '1',
            // expensesWaived deliberately left unticked: the waiver is what makes
            // the declared expenses a donation, so it cannot be optional.
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertPageContainsText('Vous devez confirmer renoncer au remboursement de vos frais.');
        self::assertCount(0, $this->freshEntityManager()->getRepository(Declaration::class)->findAll());
    }

    #[Test]
    public function a_second_declaration_with_a_known_email_reuses_the_person(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $this->completeFlow();
        $this->client->followRedirect();

        // Same email, different case and a new address — the Person must be reused,
        // not duplicated.
        $this->completeFlow(['email' => 'Jean.Dupont@Example.TEST', 'addressStreet' => 'quai de la Fosse']);

        $entityManager = $this->freshEntityManager();
        self::assertCount(1, $entityManager->getRepository(Person::class)->findAll());
        self::assertCount(2, $entityManager->getRepository(Declaration::class)->findAll());
    }

    /**
     * The form is anonymous and recognises a volunteer by email alone, so a
     * submission proves nothing about who typed it. It used to rewrite the
     * volunteer's name and address at once: anyone who knew their email could change
     * the identity printed on their next CERFA without clicking anything. What is
     * typed now stays on the declaration until the confirmation link is opened.
     */
    #[Test]
    public function a_known_volunteers_identity_changes_only_once_the_new_declaration_is_confirmed(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $this->completeFlow();
        $this->client->followRedirect();

        $this->completeFlow(['firstName' => 'Quelqu\'un', 'lastName' => 'D\'autre', 'addressStreet' => 'quai de la Fosse']);

        $entityManager = $this->freshEntityManager();
        $entityManager->clear();
        $second = $entityManager->getRepository(Declaration::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($second);

        // Submitted, not confirmed: the record is untouched, the declaration says what was typed.
        self::assertSame('Jean Dupont', $second->getPerson()->getFullName());
        self::assertSame('rue des Jardins', $second->getPerson()->getAddress()->street);
        self::assertSame('Quelqu\'un D\'autre', $second->getSubmittedFullName());
        self::assertSame('quai de la Fosse', $second->getSubmittedAddress()->street);
        self::assertTrue($second->hasPendingIdentityChange());

        $token = $second->getConfirmationToken();
        self::assertNotNull($token);
        $this->client->request('GET', '/a/les-jardins/declaration/confirmer/'.$token->value);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $people = $entityManager->getRepository(Person::class)->findAll();
        self::assertCount(1, $people);
        self::assertSame('Quelqu\'un D\'autre', $people[0]->getFullName());
        self::assertSame('quai de la Fosse', $people[0]->getAddress()->street);
    }

    /**
     * The flow keeps its draft in the session. Without a per-tenant session key, a
     * half-filled declaration started on one association's form would reappear on
     * another's in the same browser session.
     */
    #[Test]
    public function a_draft_started_on_one_organization_does_not_leak_into_another(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        OrganizationFactory::createOne(['slug' => 'les-voiles']);

        $this->client->request('GET', '/a/les-jardins/declaration');
        $crawler = $this->client->submitForm('Suivant', $this->personStep());
        // Step 1 accepted, now showing step 2 for this organization.
        self::assertResponseIsSuccessful();
        self::assertSame('Vos actions bénévoles', $crawler->filter('.steps__item--current .steps__label')->text());

        // Same session, other association: back to a blank step 1.
        $crawler = $this->client->request('GET', '/a/les-voiles/declaration');

        self::assertResponseIsSuccessful();
        self::assertSame('Vos coordonnées', $crawler->filter('.steps__item--current .steps__label')->text());
        self::assertSame(
            '',
            $crawler->filter('input[name="'.self::FORM.'[person][firstName]"]')->attr('value') ?? '',
        );
    }

    /**
     * The form mails whatever address is typed, to anyone. One address receives five
     * confirmation mails a day from one association; the sixth submission is refused
     * and files nothing.
     */
    #[Test]
    public function one_address_can_be_sent_five_confirmation_mails_a_day(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        for ($sent = 0; $sent < 5; ++$sent) {
            $this->completeFlow();
            self::assertResponseRedirects();
        }

        // Case is not a way round it: the address is the same mailbox.
        $this->completeFlow(['email' => 'Jean.Dupont@Example.TEST']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertPageContainsText('Trop de déclarations pour cette adresse');
        self::assertEmailCount(0);
        self::assertCount(5, $this->freshEntityManager()->getRepository(Declaration::class)->findAll());

        // Another address is not affected.
        $this->completeFlow(['email' => 'marie.curie@example.test']);
        self::assertResponseRedirects();
    }

    /**
     * The three integers bind to non-nullable ints. Left blank they used to submit
     * null, and writing null into an int was a TypeError: a 500 on the public form.
     */
    #[Test]
    public function blank_journey_fields_are_zero_not_a_crash(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');
        $this->client->submitForm('Suivant', $this->personStep());

        $this->client->submitForm('Suivant', [
            self::FORM.'[actions][actions][0][task]' => $this->firstTaskId(),
            self::FORM.'[actions][actions][0][title]' => 'Régate du printemps',
            self::FORM.'[actions][actions][0][date]' => '2026-05-10',
            self::FORM.'[actions][actions][0][consecutiveDays]' => '',
            self::FORM.'[actions][actions][0][workHours]' => '6.5',
            self::FORM.'[actions][actions][0][distanceKm]' => '',
            self::FORM.'[actions][actions][0][journeys]' => '',
        ]);

        self::assertResponseIsSuccessful();
        $this->assertPageContainsText('Enregistrer ma déclaration');
    }

    /**
     * A ceiling nobody reaches by hand: without one, a single request could file
     * thousands of lines and a confirmation mail listing them all.
     */
    #[Test]
    public function a_declaration_holds_at_most_fifty_actions(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');
        $crawler = $this->client->submitForm('Suivant', $this->personStep());

        $form = $crawler->selectButton('Suivant')->form();
        $action = [
            'task' => $this->firstTaskId(),
            'title' => 'Régate du printemps',
            'date' => '2026-05-10',
            'consecutiveDays' => '1',
            'workHours' => '1',
            'distanceKm' => '0',
            'journeys' => '0',
        ];

        // Sent raw: the page renders one row, and DomCrawler refuses fields it
        // cannot see.
        $this->client->request('POST', $form->getUri(), [self::FORM => [
            'actions' => ['actions' => array_fill(0, DeclarationDraft::MAX_ACTIONS + 1, $action)],
            '_token' => $form->getValues()[self::FORM.'[_token]'] ?? '',
            'next' => '',
        ]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertPageContainsText('au plus 50 actions');
    }

    /**
     * Asserts on the page's decoded text rather than its raw HTML: Twig escapes
     * apostrophes to &#039;, so a raw-string assertion on a French message with an
     * apostrophe fails for the wrong reason.
     */
    #[Test]
    public function an_electric_vehicle_is_recorded_on_the_line(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');
        $this->client->submitForm('Suivant', $this->personStep());

        $this->client->submitForm('Suivant', [
            self::FORM.'[actions][actions][0][task]' => $this->firstTaskId(),
            self::FORM.'[actions][actions][0][title]' => 'Convoyage',
            self::FORM.'[actions][actions][0][date]' => new DateTimeImmutable('-30 days')->format('Y-m-d'),
            self::FORM.'[actions][actions][0][consecutiveDays]' => '1',
            self::FORM.'[actions][actions][0][workHours]' => '2',
            self::FORM.'[actions][actions][0][distanceKm]' => '34',
            self::FORM.'[actions][actions][0][journeys]' => '2',
            self::FORM.'[actions][actions][0][ownVehicle]' => '1',
            self::FORM.'[actions][actions][0][fiscalPower]' => FiscalPower::FIVE_CV->value,
            self::FORM.'[actions][actions][0][electricVehicle]' => '1',
        ]);
        $this->client->submitForm('Enregistrer ma déclaration', [
            self::FORM.'[legal][accuracyAttested]' => '1',
            self::FORM.'[legal][expensesWaived]' => '1',
        ]);
        self::assertResponseRedirects();

        $lines = $this->findAllOf(DeclarationAction::class);
        self::assertCount(1, $lines);
        self::assertTrue($lines[0]->isElectricVehicle());
    }

    #[Test]
    public function a_refused_step_links_each_error_to_its_field(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');
        $crawler = $this->client->submitForm('Suivant', $this->personStep(['firstName' => '', 'addressPostcode' => '440']));

        $links = $crawler->filter('.error-summary a');
        self::assertCount(2, $links);
        self::assertStringContainsString('2 points à corriger', $crawler->filter('.error-summary')->text());

        foreach ($links->each(static fn (Crawler $link): string => (string) $link->attr('href')) as $href) {
            $target = substr($href, 1);
            self::assertCount(1, $crawler->filter('#'.$target.'[name^="'.self::FORM.'[person]"]'), 'A summary link points at no field: #'.$target);
        }
        self::assertStringContainsString('Code postal', $links->eq(1)->text());
    }

    #[Test]
    public function an_error_on_an_action_line_names_the_line(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');
        $this->client->submitForm('Suivant', $this->personStep());

        $crawler = $this->client->submitForm('Suivant', [
            self::FORM.'[actions][actions][0][task]' => $this->firstTaskId(),
            self::FORM.'[actions][actions][0][title]' => '',
            self::FORM.'[actions][actions][0][date]' => new DateTimeImmutable('-30 days')->format('Y-m-d'),
            self::FORM.'[actions][actions][0][consecutiveDays]' => '1',
            self::FORM.'[actions][actions][0][workHours]' => '2',
            self::FORM.'[actions][actions][0][distanceKm]' => '0',
            self::FORM.'[actions][actions][0][journeys]' => '0',
        ]);

        $link = $crawler->filter('.error-summary a');
        self::assertCount(1, $link);
        self::assertStringStartsWith('Action 1 — ', $link->text());
        self::assertSame('#'.$crawler->filter('[name="'.self::FORM.'[actions][actions][0][title]"]')->attr('id'), $link->attr('href'));
    }

    #[Test]
    public function a_valid_step_shows_no_summary(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->client->request('GET', '/a/les-jardins/declaration');
        $crawler = $this->client->submitForm('Suivant', $this->personStep());

        self::assertCount(0, $crawler->filter('.error-summary'));
    }

    private function assertPageContainsText(string $needle): void
    {
        self::assertStringContainsString($needle, $this->client->getCrawler()->filter('body')->text());
    }

    private function assertPageDoesNotContainText(string $needle): void
    {
        self::assertStringNotContainsString($needle, $this->client->getCrawler()->filter('body')->text());
    }
}
