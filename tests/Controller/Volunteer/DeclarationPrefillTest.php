<?php

declare(strict_types=1);

namespace App\Tests\Controller\Volunteer;

use App\Entity\Organization;
use App\Entity\Person;
use App\Enum\FiscalPower;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
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
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #36: a logged-in volunteer's form opens pre-filled from « Mon profil »; nobody else's.
 */
final class DeclarationPrefillTest extends WebTestCase
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
    public function the_first_step_starts_with_the_volunteers_identity(): void
    {
        $organization = $this->association('les-jardins');
        $this->login($this->volunteer($organization, distanceKm: 17));

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        self::assertSame('Camille', $this->value($crawler, self::FORM.'[person][firstName]'));
        self::assertSame('Martin', $this->value($crawler, self::FORM.'[person][lastName]'));
        self::assertSame('rue des Lilas', $this->value($crawler, self::FORM.'[person][addressStreet]'));
        self::assertSame('44000', $this->value($crawler, self::FORM.'[person][addressPostcode]'));
    }

    #[Test]
    public function each_line_starts_with_the_profiles_distance_and_vehicle_but_no_own_vehicle(): void
    {
        $organization = $this->association('les-jardins');
        $this->login($this->volunteer($organization, distanceKm: 17, fiscalPower: FiscalPower::FIVE_CV, electric: true));

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        $crawler = $this->client->submitForm('Suivant');

        self::assertSame('17', $this->value($crawler, self::LINE.'[distanceKm]'));
        self::assertSame(FiscalPower::FIVE_CV->value, $crawler->filter('[name="'.self::LINE.'[fiscalPower]"] option[selected]')->attr('value'));
        self::assertSame('checked', $crawler->filter('[name="'.self::LINE.'[electricVehicle]"]')->attr('checked'));
        self::assertNull($crawler->filter('[name="'.self::LINE.'[ownVehicle]"]')->attr('checked'));

        // « Ajouter une action » clones the prototype: it carries the same values.
        $prototype = (string) $crawler->filter('[data-form-collection-prototype-value]')->attr('data-form-collection-prototype-value');
        self::assertStringContainsString('value="17"', $prototype);
    }

    #[Test]
    public function a_profile_without_a_distance_leaves_zero(): void
    {
        $organization = $this->association('les-jardins');
        $this->login($this->volunteer($organization, distanceKm: null));

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        $crawler = $this->client->submitForm('Suivant');

        self::assertSame('0', $this->value($crawler, self::LINE.'[distanceKm]'));
    }

    #[Test]
    public function an_anonymous_visitor_gets_an_empty_form(): void
    {
        $this->volunteer($this->association('les-jardins'), distanceKm: 17);

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        self::assertSame('', $this->value($crawler, self::FORM.'[person][firstName]'));
        self::assertSame('', $this->value($crawler, self::FORM.'[person][email]'));
    }

    #[Test]
    public function another_associations_volunteer_gets_an_empty_form(): void
    {
        $this->association('les-voiles');
        $this->login($this->volunteer($this->association('les-jardins'), distanceKm: 17));

        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-voiles/declaration');

        self::assertSame('', $this->value($crawler, self::FORM.'[person][firstName]'));
        self::assertSame('', $this->value($crawler, self::FORM.'[person][email]'));
    }

    /**
     * The reported bug: a declaration started before logging in is restored from the
     * session over the pre-filled one. Its blanks are filled now, its typed values kept.
     */
    #[Test]
    public function a_draft_started_anonymously_is_filled_where_blank_after_logging_in(): void
    {
        $organization = $this->association('les-jardins');
        $person = $this->volunteer($organization, distanceKm: 17, fiscalPower: FiscalPower::FIVE_CV, electric: true);

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        $this->client->submitForm('Suivant', $this->personStep(['firstName' => 'Camille-Typed']));

        $this->login($person);
        $crawler = $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');

        // Still on the actions step, the line now carrying the profile.
        self::assertSame('17', $this->value($crawler, self::LINE.'[distanceKm]'));
        self::assertSame(FiscalPower::FIVE_CV->value, $crawler->filter('[name="'.self::LINE.'[fiscalPower]"] option[selected]')->attr('value'));

        // And the identity typed before logging in is kept.
        $crawler = $this->client->submitForm('Précédent');
        self::assertSame('Camille-Typed', $this->value($crawler, self::FORM.'[person][firstName]'));
    }

    #[Test]
    public function a_line_set_to_zero_stays_zero(): void
    {
        $organization = $this->association('les-jardins');
        $this->login($this->volunteer($organization, distanceKm: 17));

        $this->client->request(Request::METHOD_GET, '/a/les-jardins/declaration');
        $this->client->submitForm('Suivant');
        $this->client->submitForm('Suivant', [
            self::LINE.'[task]' => $this->firstTaskId(),
            self::LINE.'[title]' => 'Sur place',
            self::LINE.'[date]' => new DateTimeImmutable('-10 days')->format('Y-m-d'),
            self::LINE.'[consecutiveDays]' => '1',
            self::LINE.'[workHours]' => '2',
            self::LINE.'[distanceKm]' => '0',
            self::LINE.'[journeys]' => '0',
        ]);

        $crawler = $this->client->submitForm('Précédent');

        self::assertSame('0', $this->value($crawler, self::LINE.'[distanceKm]'));
    }

    private function association(string $slug): Organization
    {
        return OrganizationFactory::createOne(['slug' => $slug]);
    }

    private function volunteer(Organization $organization, ?int $distanceKm, ?FiscalPower $fiscalPower = null, bool $electric = false): Person
    {
        $person = PersonFactory::new()->for($organization)->create([
            'firstName' => 'Camille',
            'lastName' => 'Martin',
            'email' => new Email('camille@example.test'),
            'address' => new Address('3', 'rue des Lilas', '44000', 'Nantes', 'FR'),
        ]);
        $person->updateVehicle($distanceKm, $fiscalPower, $electric);
        $this->entityManager()->flush();
        DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        return $person;
    }

    private function login(Person $person): void
    {
        $link = self::getContainer()->get(VolunteerLink::class)->create(
            $person->getOrganization(),
            $person->getEmail(),
            $person->getLoginLinkVersion(),
            new DateTimeImmutable('+1 hour'),
        );

        $this->client->request(Request::METHOD_POST, $link);
        self::assertResponseRedirects();
    }

    private function value(Crawler $crawler, string $name): string
    {
        return (string) $crawler->filter('[name="'.$name.'"]')->attr('value');
    }
}
