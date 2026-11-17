<?php

declare(strict_types=1);

namespace App\Tests\Controller\Volunteer;

use App\Entity\Declaration;
use App\Entity\Organization;
use App\Entity\Person;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\ReceiptFactory;
use App\Receipt\ReceiptStorage;
use App\State\DeclarationState;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use App\ValueObject\Address;
use App\ValueObject\Email;
use App\Volunteer\VolunteerLink;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The volunteer space (#34): a mailed link logs a volunteer in to ONE association's space.
 */
final class VolunteerAccountTest extends WebTestCase
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
    public function asking_for_a_link_answers_the_same_page_whatever_the_address(): void
    {
        $organization = $this->association('les-jardins');
        $this->volunteerOf($organization, 'connu@example.test');

        foreach (['connu@example.test', 'nouveau@example.test'] as $address) {
            $this->client->request(Request::METHOD_GET, '/a/les-jardins/connexion');
            $this->client->submitForm('Recevoir un lien', ['volunteer_login[email]' => $address]);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
            // Both are mailed: a first link is how an account begins.
            self::assertEmailCount(1);
        }
    }

    #[Test]
    public function an_address_gets_three_links_an_hour(): void
    {
        $this->association('les-jardins');

        for ($i = 1; $i <= 4; ++$i) {
            $this->client->request(Request::METHOD_GET, '/a/les-jardins/connexion');
            $this->client->submitForm('Recevoir un lien', ['volunteer_login[email]' => 'nouveau@example.test']);

            // The same page over the limit; only the mail is held back.
            self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
            self::assertEmailCount($i <= 3 ? 1 : 0);
        }
    }

    #[Test]
    public function one_visitor_gets_ten_links_an_hour_whatever_the_addresses(): void
    {
        $this->association('les-jardins');
        self::getContainer()->get('limiter.volunteer_link_ip')->create('127.0.0.1')->consume(10);

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/connexion');
        $this->client->submitForm('Recevoir un lien', ['volunteer_login[email]' => 'nouveau@example.test']);

        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
        self::assertEmailCount(0);
    }

    #[Test]
    public function following_the_link_logs_nobody_in_until_the_button_is_pressed(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->volunteerOf($organization, 'connu@example.test');
        $link = $this->linkFor($organization, $person);

        $this->client->request(Request::METHOD_GET, $link);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        self::assertResponseRedirects('/a/les-jardins/connexion');

        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseRedirects('/a/les-jardins/declaration');

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function staying_logged_in_sets_the_volunteer_cookie_only(): void
    {
        $organization = $this->association('les-jardins');
        $link = $this->linkFor($organization, $this->volunteerOf($organization, 'connu@example.test'));

        $this->client->request(Request::METHOD_POST, $link, ['_remember_me' => '1']);

        self::assertResponseRedirects('/a/les-jardins/declaration');
        self::assertBrowserHasCookie('REMEMBERME_VOLUNTEER', '/a/');
        self::assertBrowserNotHasCookie('REMEMBERME');
    }

    #[Test]
    public function a_link_works_once(): void
    {
        $organization = $this->association('les-jardins');
        $link = $this->linkFor($organization, $this->volunteerOf($organization, 'connu@example.test'));

        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseRedirects();

        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    #[Test]
    public function a_tampered_or_expired_link_is_refused(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->volunteerOf($organization, 'connu@example.test');

        $tampered = str_replace('connu%40example.test', 'autre%40example.test', $this->linkFor($organization, $person));
        $this->client->request(Request::METHOD_POST, $tampered);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);

        $expired = $this->linkFor($organization, $person, new DateTimeImmutable('-1 minute'));
        $this->client->request(Request::METHOD_POST, $expired);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    #[Test]
    public function a_first_link_creates_the_volunteer_from_the_profile(): void
    {
        $organization = $this->association('les-jardins');
        $link = $this->self()->create($organization, new Email('nouveau@example.test'), 0, new DateTimeImmutable('+1 hour'));

        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseRedirects('/a/les-jardins/profil');

        $this->client->followRedirect();
        $this->client->submitForm('Créer mon espace', [
            'volunteer_profile[identity][firstName]' => 'Camille',
            'volunteer_profile[identity][lastName]' => 'Neuve',
            'volunteer_profile[identity][addressStreet]' => 'rue des Lilas',
            'volunteer_profile[identity][addressPostcode]' => '44000',
            'volunteer_profile[identity][addressCity]' => 'Nantes',
            'volunteer_profile[distanceKm]' => '17',
            'volunteer_profile[fiscalPower]' => FiscalPower::FOUR_CV->value,
            'volunteer_profile[electricVehicle]' => '1',
        ]);
        self::assertResponseRedirects('/a/les-jardins/declaration');

        $person = $this->findOne(Person::class, ['email' => 'nouveau@example.test']);
        self::assertSame('Camille Neuve', $person->getFullName());
        self::assertSame(17, $person->getDistanceKm());
        self::assertSame(FiscalPower::FOUR_CV, $person->getFiscalPower());
        self::assertTrue($person->isElectricVehicle());

        // Logged in, and the first link is spent.
        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        self::assertResponseIsSuccessful();
        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    #[Test]
    public function the_profile_edits_the_vehicle_and_never_the_identity(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->loginVolunteer($organization, 'connu@example.test');

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/profil');
        self::assertCount(0, $crawler->filter('[name^="volunteer_profile[identity]"]'));

        $this->client->submitForm('Enregistrer', [
            'volunteer_profile[distanceKm]' => '42',
            'volunteer_profile[fiscalPower]' => FiscalPower::SIX_CV->value,
        ]);
        self::assertResponseRedirects('/a/les-jardins/profil');

        $reloaded = $this->reload($person);
        self::assertSame(42, $reloaded->getDistanceKm());
        self::assertSame(FiscalPower::SIX_CV, $reloaded->getFiscalPower());
        self::assertSame($person->getFullName(), $reloaded->getFullName());
    }

    #[Test]
    public function a_login_is_for_one_association_only(): void
    {
        $mine = $this->association('les-jardins');
        $this->association('les-voiles');
        $this->loginVolunteer($mine, 'connu@example.test');

        $this->client->request(Request::METHOD_GET, '/a/les-voiles/mes-declarations');
        self::assertResponseRedirects('/a/les-voiles/connexion');

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-voiles/declaration');
        self::assertStringContainsString('Se connecter', $crawler->filter('.public__account')->text());

        // And still logged in at home.
        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function a_logged_in_declaration_is_confirmed_at_submission_without_a_mail(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->loginVolunteer($organization, 'connu@example.test');

        // The typed address is ignored: the declaration is the account's.
        $this->completeFlow(['email' => 'autre@example.test', 'addressCity' => 'Rezé']);

        self::assertResponseRedirects('/a/les-jardins/mes-declarations');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Votre déclaration est enregistrée');

        // The one with lines: volunteerOf() gave the volunteer an earlier, empty one.
        $filed = array_values(array_filter(
            $this->findAllOf(Declaration::class),
            static fn (Declaration $declaration): bool => !$declaration->getActions()->isEmpty(),
        ));
        self::assertCount(1, $filed);
        self::assertSame(DeclarationState::SUBMITTED, $filed[0]->getState());
        self::assertTrue($filed[0]->getPerson()->getId()->equals($person->getId()));
        // Confirmed, so the typed identity reached the Person.
        self::assertSame('Rezé', $this->reload($person)->getAddress()->city);
    }

    #[Test]
    public function a_logged_in_volunteers_email_is_shown_and_locked(): void
    {
        $organization = $this->association('les-jardins');
        $this->loginVolunteer($organization, 'connu@example.test');

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        $field = $crawler->filter('[name="'.self::FORM.'[person][email]"]');

        self::assertSame('connu@example.test', $field->attr('value'));
        self::assertSame('readonly', $field->attr('readonly'));
    }

    #[Test]
    public function a_volunteer_sees_and_downloads_only_their_own_receipts(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->loginVolunteer($organization, 'connu@example.test');
        $path = self::getContainer()->get(ReceiptStorage::class)->store(2025, $person, '0001', '%PDF-1.4 test');
        $mine = ReceiptFactory::createOne(['person' => $person, 'number' => '0001', 'storagePath' => $path]);
        $theirs = ReceiptFactory::createOne(['person' => $this->volunteerOf($organization, 'autre@example.test'), 'number' => '0002']);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-recus');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('0001', $crawler->filter('main')->text());
        self::assertStringNotContainsString('0002', $crawler->filter('main')->text());

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-recus/'.$mine->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-recus/'.$theirs->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function a_back_office_session_and_a_volunteer_session_coexist(): void
    {
        $organization = $this->association('les-jardins');
        $this->loginAsAdminOf($organization);
        $this->loginVolunteer($organization, 'connu@example.test');

        $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();
        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function logging_out_ends_the_volunteer_session(): void
    {
        $organization = $this->association('les-jardins');
        $this->loginVolunteer($organization, 'connu@example.test');

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        $this->client->submit($crawler->selectButton('Se déconnecter')->form());
        self::assertResponseRedirects('/a/les-jardins/declaration');

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');
        self::assertResponseRedirects('/a/les-jardins/connexion');
    }

    #[Test]
    public function my_declarations_lists_declarations_not_lines(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->loginVolunteer($organization, 'connu@example.test');
        $declaration = DeclarationFactory::new()->forPerson($person)->confirmed()->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->create(['title' => 'Régate de printemps', 'workHours' => '2.50']);
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->create(['title' => 'Chantier', 'workHours' => '1.00']);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations');

        // loginVolunteer() gave them an earlier, empty one: two declarations, two rows.
        self::assertCount(2, $crawler->filter('main tbody tr'));
        self::assertStringContainsString("3,50\u{a0}h", $crawler->filter('main tbody')->text());
        self::assertStringNotContainsString('Régate de printemps', $crawler->filter('main')->text());
    }

    #[Test]
    public function a_declarations_page_shows_its_lines_and_only_to_its_volunteer(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->loginVolunteer($organization, 'connu@example.test');
        $mine = DeclarationFactory::new()->forPerson($person)->confirmed()->create();
        DeclarationActionFactory::new()->forDeclaration($mine)->confirmed()->create(['title' => 'Régate de printemps']);
        $theirs = DeclarationFactory::new()->forPerson($this->volunteerOf($organization, 'autre@example.test'))->confirmed()->create();

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations/'.$mine->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Régate de printemps', $crawler->filter('main')->text());

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations/'.$theirs->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function the_header_has_the_spaces_tabs_and_marks_the_current_one(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->loginVolunteer($organization, 'connu@example.test');
        $declaration = DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/mes-declarations/'.$declaration->getId()->toRfc4122());

        self::assertSame(['Déclarer', 'Mes déclarations', 'Mes reçus', 'Mon profil'], $crawler->filter('.public__tab')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame('Mes déclarations', $crawler->filter('.public__tab[aria-current="page"]')->text());
        self::assertStringContainsString($person->getFullName(), $crawler->filter('.public__bar')->text());
    }

    #[Test]
    public function an_anonymous_header_offers_only_to_log_in(): void
    {
        $this->association('les-jardins');

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        self::assertCount(0, $crawler->filter('.public__tabs'));
        self::assertSame('Se connecter', trim($crawler->filter('.public__account')->text()));
    }

    private function association(string $slug): Organization
    {
        return OrganizationFactory::createOne(['slug' => $slug]);
    }

    private function volunteerOf(Organization $organization, string $email): Person
    {
        $person = PersonFactory::new()->for($organization)->create([
            'email' => new Email($email),
            'address' => new Address('3', 'rue des Jardins', '44000', 'Nantes', 'FR'),
        ]);
        // A volunteer has declared before: that is what put them here.
        DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        return $person;
    }

    private function loginVolunteer(Organization $organization, string $email): Person
    {
        $person = $this->volunteerOf($organization, $email);
        $this->client->request(Request::METHOD_POST, $this->linkFor($organization, $person));
        self::assertResponseRedirects();

        return $person;
    }

    private function linkFor(Organization $organization, Person $person, ?DateTimeImmutable $expiresAt = null): string
    {
        return $this->self()->create(
            $organization,
            $person->getEmail(),
            $person->getLoginLinkVersion(),
            $expiresAt ?? new DateTimeImmutable('+1 hour'),
        );
    }

    private function self(): VolunteerLink
    {
        return self::getContainer()->get(VolunteerLink::class);
    }
}
