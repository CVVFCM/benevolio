<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\EventType;
use App\Entity\Organization;
use App\Entity\Person;
use App\Factory\OrganizationFactory;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
    use ResetDatabase;
    private const string FORM = 'declaration_flow';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
        self::assertSelectorTextContains('h1', 'Votre déclaration est enregistrée');

        $entityManager = $this->entityManager();
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

        self::assertSame(DeclarationState::SUBMITTED, $declaration->getState());
        self::assertTrue($declaration->isAccuracyAttested());
        self::assertTrue($declaration->areExpensesWaived());
        self::assertCount(1, $declaration->getActions());

        $action = $declaration->getActions()->first();
        self::assertNotFalse($action);
        self::assertSame(DeclarationActionState::SUBMITTED, $action->getState());
        self::assertSame('Régate du printemps', $action->getTitle());
        // "6.5" typed, stored with the two decimals the column holds.
        self::assertSame('6.50', $action->getWorkHours());
        // One-way distance × journeys.
        self::assertSame(24, $action->getTotalDistanceKm());
    }

    /**
     * The event type is a Doctrine entity carried inside the session-stored draft,
     * which SessionDataStorage deep-clones — detaching it. DeclarationSubmitter
     * re-fetches it for that reason; without that the submission dies with
     * "A new entity was found through the relationship". This asserts the action
     * ends up pointing at the *existing* row rather than a duplicate.
     */
    #[Test]
    public function the_chosen_event_type_survives_the_session_round_trip(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $typesBefore = $this->entityManager()->getRepository(EventType::class)->count(['organization' => $organization]);

        $this->completeFlow();
        $this->client->followRedirect();

        $entityManager = $this->entityManager();
        // No stray copy of the type was created by the round trip.
        self::assertSame(
            $typesBefore,
            $entityManager->getRepository(EventType::class)->count(['organization' => $organization]),
        );

        $actions = $entityManager->getRepository(DeclarationAction::class)->findAll();
        self::assertCount(1, $actions);
        $eventType = reset($actions)->getEventType();
        // And it belongs to this association, not to a detached orphan.
        self::assertSame(
            $organization->getId()->toRfc4122(),
            $eventType->getOrganization()->getId()->toRfc4122(),
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
        self::assertCount(0, $this->entityManager()->getRepository(Declaration::class)->findAll());
    }

    #[Test]
    public function a_second_declaration_with_a_known_email_reuses_the_person(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $this->completeFlow();
        $this->client->followRedirect();

        // Same email, different case and a new address — the Person must be reused
        // and updated, not duplicated.
        $this->completeFlow(['email' => 'Jean.Dupont@Example.TEST', 'addressStreet' => 'quai de la Fosse']);

        $entityManager = $this->entityManager();
        $people = $entityManager->getRepository(Person::class)->findAll();
        self::assertCount(1, $people);
        self::assertCount(2, $entityManager->getRepository(Declaration::class)->findAll());

        $person = reset($people);
        self::assertSame('quai de la Fosse', $person->getAddress()->street);
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
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function personStep(array $overrides = []): array
    {
        $values = [
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
            'email' => 'jean.dupont@example.test',
            'addressNumber' => '12',
            'addressStreet' => 'rue des Jardins',
            'addressPostcode' => '44000',
            'addressCity' => 'Nantes',
            'addressCountry' => 'FR',
            ...$overrides,
        ];

        $fields = [];
        foreach ($values as $name => $value) {
            $fields[self::FORM.'[person]['.$name.']'] = $value;
        }

        return $fields;
    }

    /**
     * @param array<string, string> $personOverrides
     */
    private function reachLegalStep(array $personOverrides = []): void
    {
        $this->client->request('GET', '/a/les-jardins/declaration');

        // Advancing a step renders the next one directly; FormFlow does not do
        // POST-redirect-GET between steps (it guards against a POST reload itself
        // via isCurrentStepSubmitted()). Only the final submit redirects.
        $this->client->submitForm('Suivant', $this->personStep($personOverrides));

        // The event type is a database row now, not an enum value, so the submitted
        // value is its id — taken from the rendered select rather than hardcoded, so
        // the test breaks if the option ever stops being offered.
        $this->client->submitForm('Suivant', [
            self::FORM.'[actions][actions][0][eventType]' => $this->firstEventTypeId(),
            self::FORM.'[actions][actions][0][title]' => 'Régate du printemps',
            self::FORM.'[actions][actions][0][date]' => '2026-05-10',
            self::FORM.'[actions][actions][0][consecutiveDays]' => '2',
            self::FORM.'[actions][actions][0][workHours]' => '6.5',
            self::FORM.'[actions][actions][0][distanceKm]' => '12',
            self::FORM.'[actions][actions][0][journeys]' => '2',
        ]);
    }

    /**
     * @param array<string, string> $personOverrides
     */
    private function completeFlow(array $personOverrides = []): void
    {
        $this->reachLegalStep($personOverrides);

        $this->client->submitForm('Enregistrer ma déclaration', [
            self::FORM.'[legal][accuracyAttested]' => '1',
            self::FORM.'[legal][expensesWaived]' => '1',
        ]);
    }

    /**
     * Asserts on the page's decoded text rather than its raw HTML: Twig escapes
     * apostrophes to &#039;, so a raw-string assertion on a French message with an
     * apostrophe fails for the wrong reason.
     */
    private function assertPageContainsText(string $needle): void
    {
        self::assertStringContainsString($needle, $this->client->getCrawler()->filter('body')->text());
    }

    private function assertPageDoesNotContainText(string $needle): void
    {
        self::assertStringNotContainsString($needle, $this->client->getCrawler()->filter('body')->text());
    }

    /**
     * The id of the first event type the form actually offers.
     */
    private function firstEventTypeId(): string
    {
        $option = $this->client->getCrawler()
            ->filter('select[name="'.self::FORM.'[actions][actions][0][eventType]"] option[value!=""]')
            ->first();

        self::assertGreaterThan(0, $option->count(), 'The actions step offers no event type.');

        return (string) $option->attr('value');
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }
}
