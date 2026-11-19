<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Declaration;
use App\Entity\Person;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\State\DeclarationState;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
use App\ValueObject\Email as EmailAddress;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Double opt-in, end to end: a declaration exists but does nothing until the
 * volunteer opens the link emailed to them.
 *
 * The click is also what proves the address works, which is what a CERFA receipt
 * will eventually have to be sent to — so "the mail was actually addressed to the
 * person who filled the form" is asserted, not assumed.
 */
final class DeclarationConfirmationTest extends WebTestCase
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
    public function submitting_sends_a_confirmation_email_to_the_volunteer(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins', 'name' => 'Les Jardins']);

        $this->completeFlow();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        self::assertSame(
            ['jean.dupont@example.test'],
            array_map(static fn (Address $to): string => $to->getAddress(), $email->getTo()),
        );
        self::assertStringContainsString('Confirmez votre déclaration', (string) $email->getSubject());
        // The link, and the recap, both have to be there.
        self::assertStringContainsString($this->confirmationUrl(), $email->getHtmlBody().$email->getTextBody());
        self::assertStringContainsString('Régate du printemps', (string) $email->getTextBody());
    }

    /**
     * Mail scanners open every link in a message before its reader does. Opening the
     * link must therefore change nothing — or anyone who knows a volunteer's email could
     * have a made-up identity applied to their record by a machine.
     */
    #[Test]
    public function opening_the_link_confirms_nothing(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();

        $this->client->request('GET', $this->confirmationUrl());
        $this->client->request('GET', $this->confirmationUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Confirmez votre déclaration');
        self::assertSelectorExists('form[method="post"] button');

        $declaration = $this->reloadDeclaration();
        self::assertSame(DeclarationState::AWAITING_CONFIRMATION, $declaration->getState());
        self::assertFalse($declaration->isConfirmed());
    }

    #[Test]
    public function the_button_confirms_the_declaration(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();

        $this->client->request('GET', $this->confirmationUrl());
        $this->client->submitForm('Confirmer ma déclaration');

        // Back to the link itself, which now reads « confirmée »: a reload repeats nothing.
        self::assertResponseRedirects($this->confirmationUrl(), Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre déclaration est confirmée');

        $declaration = $this->reloadDeclaration();
        self::assertSame(DeclarationState::SUBMITTED, $declaration->getState());
        self::assertTrue($declaration->isConfirmed());
    }

    /**
     * The attack the button exists to stop: someone files a declaration under a known
     * volunteer's email, with another name and address. A prefetch of the mailed link
     * leaves the record alone, and the page says whose identity is being confirmed.
     */
    #[Test]
    public function a_prefetch_does_not_apply_an_identity_typed_by_someone_else(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        PersonFactory::new()->for($organization)->create([
            'email' => new EmailAddress('jean.dupont@example.test'),
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]);

        $this->completeFlow(['firstName' => 'Mallory', 'lastName' => 'Usurpe', 'addressStreet' => 'rue du Pirate']);
        $crawler = $this->client->request('GET', $this->confirmationUrl());

        $person = $this->findOne(Person::class, ['email' => 'jean.dupont@example.test']);
        self::assertSame('Jean', $person->getFirstName());
        self::assertSame('Dupont', $person->getLastName());
        self::assertStringNotContainsString('Pirate', (string) $person->getAddress());

        $page = $crawler->filter('.done')->text();
        self::assertStringContainsString('Mallory Usurpe', $page);
        self::assertStringContainsString('rue du Pirate', $page);
        self::assertStringContainsString('remplaceront celles que l\'association a enregistrées', $page);
        self::assertStringContainsString('Jean Dupont', $page);
    }

    /**
     * Nothing to warn about when the declaration states what the record already says.
     */
    #[Test]
    public function the_page_does_not_warn_when_the_identity_is_unchanged(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();

        $crawler = $this->client->request('GET', $this->confirmationUrl());

        $page = $crawler->filter('.done')->text();
        self::assertStringContainsString('Jean Dupont', $page);
        self::assertStringNotContainsString('remplaceront', $page);
    }

    /**
     * The address is the credential: it must not leak through a Referer, nor sit in a cache.
     */
    #[Test]
    public function the_page_keeps_its_address_out_of_referers_and_caches(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();

        $this->client->request('GET', $this->confirmationUrl());

        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * The mail does not greet by a name: for a new address that name is whatever the
     * sender of the form typed, and for a known one it would vouch for a declaration
     * its holder may not have made.
     */
    #[Test]
    public function the_mail_greets_nobody_by_name(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow(['firstName' => 'URGENT votre compte']);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('Bonjour,', (string) $email->getTextBody());
        self::assertStringNotContainsString('Bonjour URGENT', $email->getHtmlBody().$email->getTextBody());
    }

    /**
     * Volunteers come back to the link. That must not look like a failure, nor confirm twice.
     */
    #[Test]
    public function a_second_visit_still_shows_success(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();
        $url = $this->confirmationUrl();

        $this->client->request('POST', $url);
        $firstConfirmedAt = $this->reloadDeclaration()->getConfirmedAt();
        self::assertNotNull($firstConfirmedAt);

        $this->client->request('POST', $url);
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre déclaration est confirmée');
        self::assertSelectorNotExists('form[method="post"]');
        // Idempotent: the second click does not restamp the confirmation.
        self::assertEquals($firstConfirmedAt, $this->reloadDeclaration()->getConfirmedAt());
    }

    #[Test]
    public function an_unknown_token_is_a_404(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/a/les-jardins/declaration/confirmer/'.str_repeat('a', 43));

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * A link is good under its own association's address only — including for that
     * association's admin, whose account must not stand in for an address that
     * resolves nothing.
     */
    #[Test]
    public function a_logged_in_admin_cannot_confirm_through_an_unknown_address(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create();
        $token = \App\Declaration\ConfirmationToken::generate();
        $declaration->issueConfirmationToken($token, new DateTimeImmutable('+7 days'));
        $this->entityManager()->flush();
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', '/a/does-not-exist/declaration/confirmer/'.$token->value);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(DeclarationState::AWAITING_CONFIRMATION, $this->reloadDeclaration()->getState());
    }

    #[Test]
    public function an_expired_link_says_so_and_leaves_the_declaration_alone(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create();

        // Issued in the past, so it is already beyond its 7-day life.
        $token = \App\Declaration\ConfirmationToken::generate();
        $declaration->issueConfirmationToken($token, new DateTimeImmutable('-1 day'));
        $this->entityManager()->flush();

        $this->client->request('GET', '/a/les-jardins/declaration/confirmer/'.$token->value);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorTextContains('h1', 'Ce lien a expiré');
        self::assertSelectorNotExists('form[method="post"]');

        // And the button's request, sent anyway, confirms nothing either.
        $this->client->request('POST', '/a/les-jardins/declaration/confirmer/'.$token->value);
        self::assertTrue($this->reloadDeclaration()->getState()->isAwaitingConfirmation());
    }

    /**
     * A token belongs to the association whose URL it was issued under. Redeeming
     * it through another association's address must not work — the tenant filter
     * is what stops it.
     */
    #[Test]
    public function a_token_cannot_be_redeemed_through_another_organization(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        OrganizationFactory::createOne(['slug' => 'les-voiles']);
        $this->completeFlow();

        $token = $this->reloadDeclaration()->getConfirmationToken();
        self::assertNotNull($token);

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/a/les-voiles/declaration/confirmer/'.$token->value);

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertTrue($this->reloadDeclaration()->getState()->isAwaitingConfirmation());
        }
    }

    /**
     * Asserts the expiry actually written to the row, not the constant compared to
     * itself — a volunteer gets a week, and a link that silently expired sooner
     * would cost them the whole form.
     */
    #[Test]
    public function the_link_is_valid_for_seven_days(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();

        $expiresAt = $this->reloadDeclaration()->getConfirmationTokenExpiresAt();

        self::assertNotNull($expiresAt);
        self::assertEqualsWithDelta(
            new DateTimeImmutable('+7 days')->getTimestamp(),
            $expiresAt->getTimestamp(),
            60,
        );
    }

    private function confirmationUrl(): string
    {
        $token = $this->reloadDeclaration()->getConfirmationToken();
        self::assertNotNull($token);

        return '/a/les-jardins/declaration/confirmer/'.$token->value;
    }

    /**
     * The one declaration the flow filed, read back from the database.
     */
    private function reloadDeclaration(): Declaration
    {
        $declarations = $this->findAllOf(Declaration::class);
        self::assertCount(1, $declarations);

        return $declarations[0];
    }
}
