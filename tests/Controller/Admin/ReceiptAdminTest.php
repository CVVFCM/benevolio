<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\Receipt;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function sprintf;

/**
 * The « Reçus fiscaux » pages: choosing a year, running it, and reading the result.
 *
 * Needs real Gotenberg and s3mock, like the run itself — generating is the point of the
 * page, and a stubbed generator would leave the interesting half untested.
 */
final class ReceiptAdminTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_year_form_offers_only_years_with_validated_actions(): void
    {
        $organization = $this->association();
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');
        $this->loginAdminOf($organization);

        $crawler = $this->client->request('GET', '/admin/receipt/batch/choose-year');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select option')->each(
            static fn (object $node): string => (string) $node->attr('value'),
        );
        self::assertSame(['2025'], $options);
    }

    /**
     * The form must post to a PATH, never to an absolute URL.
     *
     * In production TLS terminates at the Gateway, so the pod sees plain HTTP and EasyAdmin's
     * absolute URLs came out as `http://…`: the browser warned that the data would travel in
     * the clear, the edge redirected to https, the redirect turned the POST into a GET, and
     * this POST-only route answered 405. `Dashboard::generateRelativeUrls()` is what prevents
     * it, and nothing else in the test suite would notice it being removed.
     */
    #[Test]
    public function the_form_posts_to_a_relative_url(): void
    {
        $organization = $this->association();
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');
        $this->loginAdminOf($organization);

        $crawler = $this->client->request('GET', '/admin/receipt/batch/choose-year');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/admin/receipt/batch/generate',
            // By name: the first form on an EasyAdmin page is its own search box.
            $crawler->filter('form[name="receipt_year"]')->attr('action'),
        );
    }

    #[Test]
    public function generating_a_year_issues_and_reports_a_receipt(): void
    {
        $organization = $this->association();
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');
        $this->loginAdminOf($organization);

        $this->submitYear(2025);

        self::assertResponseIsSuccessful();
        $text = $this->client->getCrawler()->filter('body')->text();
        // 68 km at the 5 CV rate of 0,636 → 43,25 €.
        self::assertStringContainsString('0001', $text);
        self::assertStringContainsString('43,25', $text);

        self::assertSame(2025, $this->onlyReceipt()->getYear());
    }

    /**
     * The guard on an unfinished year: choosing it without acknowledging the consequence is
     * refused, and nothing is issued.
     */
    #[Test]
    public function the_current_year_needs_the_acknowledgement(): void
    {
        $organization = $this->association();
        $currentYear = (int) new DateTimeImmutable()->format('Y');
        $this->volunteerWithWaivedTravel($organization, sprintf('%d-06-21', $currentYear));
        $this->loginAdminOf($organization);

        $this->submitYear($currentYear, acknowledge: false);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager()->getRepository(Receipt::class)->findAll());

        $this->submitYear($currentYear, acknowledge: true);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->entityManager()->getRepository(Receipt::class)->findAll());
    }

    /**
     * Issuing tax documents and emailing them must not be reachable by following a link, or
     * by a browser prefetching one.
     */
    #[Test]
    public function generation_refuses_a_get(): void
    {
        $organization = $this->association();
        $this->loginAdminOf($organization);

        $this->client->request('GET', '/admin/receipt/batch/generate');

        self::assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function a_receipt_can_be_downloaded_again(): void
    {
        $organization = $this->association();
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');
        $this->loginAdminOf($organization);
        $this->submitYear(2025);

        $this->client->request('GET', '/admin/receipt/'.$this->onlyReceipt()->getId()->toRfc4122().'/download');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function another_associations_receipt_is_not_downloadable(): void
    {
        $theirs = $this->association();
        $this->volunteerWithWaivedTravel($theirs, '2025-06-21');
        $this->loginAdminOf($theirs);
        $this->submitYear(2025);

        $id = $this->onlyReceipt()->getId();

        // A different association's admin, and a fresh manager: the identity map would
        // otherwise hand back a row the filtered SQL never returned.
        $this->loginAdminOf($this->association());
        $this->entityManager()->clear();

        $this->client->request('GET', '/admin/receipt/'.$id->toRfc4122().'/download');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function an_association_without_a_siren_is_told_why_nothing_was_issued(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');
        $this->loginAdminOf($organization);

        $this->submitYear(2025);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'SIREN',
            $this->client->getCrawler()->filter('body')->text(),
        );
        self::assertCount(0, $this->entityManager()->getRepository(Receipt::class)->findAll());
    }

    #[Test]
    public function the_dashboard_links_to_the_form(): void
    {
        $organization = $this->association();
        $this->loginAdminOf($organization);

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href*="choose-year"]')->count());
    }

    /**
     * Through the rendered form, not a hand-built POST: the form carries the CSRF token, and
     * a raw request is simply rejected — which is the right behaviour and a useless test.
     */
    private function submitYear(int $year, bool $acknowledge = false): void
    {
        $crawler = $this->client->request('GET', '/admin/receipt/batch/choose-year');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Générer et envoyer')->form();

        $yearField = $form['receipt_year[year]'];
        self::assertInstanceOf(ChoiceFormField::class, $yearField);
        $yearField->select((string) $year);

        if ($acknowledge) {
            $acknowledgement = $form['receipt_year[partialYearAcknowledged]'];
            self::assertInstanceOf(ChoiceFormField::class, $acknowledgement);
            $acknowledgement->tick();
        }

        $this->client->submit($form);
    }

    private function association(): Organization
    {
        return OrganizationFactory::new()->withCerfaIdentity()->withSignature()->create();
    }

    /**
     * A volunteer with one validated line of waived travel: 2 journeys of 34 km in their own
     * vehicle, which at the 5 CV rate is 43,25 €.
     */
    private function volunteerWithWaivedTravel(Organization $organization, string $date): Person
    {
        FiscalYearFactory::new()->for($organization)
            ->calendarYear((int) new DateTimeImmutable($date)->format('Y'))
            ->withPublishedBareme()
            ->create();

        $person = PersonFactory::createOne(['organization' => $organization]);
        $declaration = DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        DeclarationActionFactory::new()->forDeclaration($declaration)->validated()->create([
            'date' => new DateTimeImmutable($date),
            'workHours' => '2.00',
            'journeys' => 2,
            'distanceKm' => 34,
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
        ]);

        return $person;
    }

    private function loginAdminOf(Organization $organization): void
    {
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());
    }

    /**
     * The single receipt a test has just had issued, with its presence asserted rather than
     * assumed — an empty table would otherwise fail on a null further down, saying nothing.
     */
    private function onlyReceipt(): Receipt
    {
        $receipts = $this->entityManager()->getRepository(Receipt::class)->findAll();
        self::assertCount(1, $receipts);

        return $receipts[0];
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
