<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Declaration;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\State\DeclarationState;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\ResetsRateLimiters;
use App\Tests\Support\WalksDeclarationFlow;
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

    #[Test]
    public function following_the_link_confirms_the_declaration(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();

        $this->client->request('GET', $this->confirmationUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre déclaration est confirmée');

        $declaration = $this->reloadDeclaration();
        self::assertSame(DeclarationState::SUBMITTED, $declaration->getState());
        self::assertTrue($declaration->isConfirmed());
    }

    /**
     * Volunteers click twice and mail clients prefetch links. Neither must look
     * like a failure.
     */
    #[Test]
    public function a_second_click_still_shows_success(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->completeFlow();
        $url = $this->confirmationUrl();

        $this->client->request('GET', $url);
        $firstConfirmedAt = $this->reloadDeclaration()->getConfirmedAt();

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre déclaration est confirmée');
        // Idempotent: the second visit does not restamp the confirmation.
        self::assertEquals($firstConfirmedAt, $this->reloadDeclaration()->getConfirmedAt());
    }

    #[Test]
    public function an_unknown_token_is_a_404(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);

        $this->client->request('GET', '/a/les-jardins/declaration/confirmer/'.str_repeat('a', 43));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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

        $this->client->request('GET', '/a/les-voiles/declaration/confirmer/'.$token->value);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertTrue($this->reloadDeclaration()->getState()->isAwaitingConfirmation());
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
