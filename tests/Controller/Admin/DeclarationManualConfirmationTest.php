<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Declaration\DeclarationConfirmer;
use App\Declaration\Exception\DeclarationNotConfirmableException;
use App\Doctrine\Filter\OrganizationFilter;
use App\Entity\Declaration;
use App\Entity\Organization;
use App\Entity\User;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Finite\StateMachine;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * "Confirmer à la place du bénévole": the escape hatch for a confirmation mail that
 * never arrived, through the real back-office — the button, the modal's wording,
 * the POST with its CSRF token, and what gets recorded.
 */
final class DeclarationManualConfirmationTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * The factory issues no token, which isConfirmationTokenExpired() treats as
     * expired — so this is also the proof that an expired link does not block it.
     */
    #[Test]
    public function an_admin_confirms_in_the_volunteers_stead_and_is_recorded(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->unconfirmedDeclarationWithLines($organization, 2);
        $admin = $this->loginAsAdminOf($organization);

        $this->client->submit($this->confirmForm($declaration));

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('confirmée manuellement', $this->bodyText());

        $reloaded = $this->reload($declaration);
        self::assertSame(DeclarationState::SUBMITTED, $reloaded->getState());
        self::assertNotNull($reloaded->getConfirmedAt());
        self::assertTrue($reloaded->isConfirmedManually());
        self::assertSame($admin->getId()->toRfc4122(), $reloaded->getConfirmedBy()?->getId()->toRfc4122());

        // The same `confirm` transition as the link, so the lines moved with it.
        self::assertCount(2, $reloaded->getActions());
        foreach ($reloaded->getActions() as $action) {
            self::assertSame(DeclarationActionState::SUBMITTED, $action->getState());
        }

        // The detail page says who vouched, and the button is gone.
        self::assertStringContainsString('par '.$admin->getEmail().', à la place du bénévole', $this->bodyText());
        self::assertStringNotContainsString('Confirmer à la place du bénévole', $this->bodyText());
    }

    /**
     * The point of the modal: an admin must be told this is not routine before
     * the request is sent.
     */
    #[Test]
    public function the_button_warns_that_this_is_not_a_normal_operation(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->unconfirmedDeclarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request(Request::METHOD_GET, $this->detailUrl($declaration));

        $button = $crawler->filter('form[action*="confirm-manually"] button');
        self::assertCount(1, $button);
        self::assertSame('true', $button->attr('data-action-confirmation'));
        self::assertStringContainsString('pas une opération normale', (string) $button->attr('data-action-confirmation-content'));
        self::assertSame('POST', strtoupper((string) $crawler->filter('form[action*="confirm-manually"]')->attr('method')));
    }

    #[Test]
    public function a_declaration_the_volunteer_confirmed_offers_no_button_and_says_so(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request(Request::METHOD_GET, $this->detailUrl($declaration));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="confirm-manually"]'));
        self::assertStringContainsString('par le bénévole, via le lien reçu par courriel', $this->bodyText());
    }

    #[Test]
    public function a_request_without_a_valid_csrf_token_changes_nothing(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->unconfirmedDeclarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $this->client->request(Request::METHOD_POST, $this->confirmUrl($declaration), ['token' => 'forged']);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('La page a expiré', $this->bodyText());
        self::assertTrue($this->reload($declaration)->getState()->isAwaitingConfirmation());
    }

    /**
     * A token for one declaration must not confirm another.
     */
    #[Test]
    public function a_token_minted_for_another_declaration_is_refused(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $first = $this->unconfirmedDeclarationWithLines($organization, 1);
        $second = $this->unconfirmedDeclarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $token = $this->confirmForm($first)->getValues()['token'] ?? null;
        self::assertIsString($token);

        $this->client->request(Request::METHOD_POST, $this->confirmUrl($second), ['token' => $token]);

        $this->client->followRedirect();
        self::assertStringContainsString('La page a expiré', $this->bodyText());
        self::assertTrue($this->reload($second)->getState()->isAwaitingConfirmation());
    }

    /**
     * A state change that skips the double opt-in must not be reachable from a
     * link, prefetched or pasted.
     */
    #[Test]
    public function get_is_not_accepted(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->unconfirmedDeclarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $this->client->request(Request::METHOD_GET, $this->confirmUrl($declaration));

        self::assertFalse($this->client->getResponse()->isSuccessful() || $this->client->getResponse()->isRedirection());
        self::assertTrue($this->reload($declaration)->getState()->isAwaitingConfirmation());
    }

    /**
     * The volunteer clicked between the page loading and the admin confirming:
     * reported, and the record stays the volunteer's own.
     */
    #[Test]
    public function a_declaration_confirmed_in_the_meantime_is_reported_not_overwritten(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->unconfirmedDeclarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $form = $this->confirmForm($declaration);

        // The volunteer's click, as DeclarationConfirmer::confirm() makes it.
        $loaded = $this->reload($declaration);
        self::getContainer()->get(StateMachine::class)->apply($loaded, DeclarationState::TRANSITION_CONFIRM);
        $loaded->markConfirmed(new DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString('n\'attend plus de confirmation', $this->bodyText());
        self::assertFalse($this->reload($declaration)->isConfirmedManually());
    }

    /**
     * Declaration is TenantAware, so another association's row does not load.
     */
    #[Test]
    public function another_associations_declaration_is_not_found(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voisins']);
        $declaration = $this->unconfirmedDeclarationWithLines($theirs, 1);
        $this->loginAsAdminOf($mine);

        // Without this the request is handed Foundry's instance from the shared
        // identity map, which no SQL filter ever sees — the test would prove nothing.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request(Request::METHOD_POST, $this->confirmUrl($declaration), ['token' => 'whatever']);

        self::assertResponseStatusCodeSame(404);
        $this->disableTenantFilter();
        self::assertTrue($this->reload($declaration)->getState()->isAwaitingConfirmation());
    }

    #[Test]
    public function the_service_refuses_a_declaration_not_awaiting_confirmation(): void
    {
        $organization = OrganizationFactory::createOne();
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();
        $admin = UserFactory::new()->admin($organization)->create();
        $confirmer = self::getContainer()->get(DeclarationConfirmer::class);

        self::assertFalse($confirmer->canConfirmManually($declaration));

        $this->expectException(DeclarationNotConfirmableException::class);
        $confirmer->confirmManually($declaration, $admin);
    }

    /**
     * Lines are created before anything confirms the declaration — the cascade only
     * moves lines that exist at that moment (see AGENTS.md, fixtures trap).
     */
    private function unconfirmedDeclarationWithLines(Organization $organization, int $count): Declaration
    {
        $declaration = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->many($count)->create();

        return $declaration;
    }

    private function confirmForm(Declaration $declaration): Form
    {
        $crawler = $this->client->request(Request::METHOD_GET, $this->detailUrl($declaration));
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[action*="confirm-manually"]')->form();
    }

    private function loginAsAdminOf(Organization $organization): User
    {
        $admin = UserFactory::new()->admin($organization)->create();
        $this->client->loginUser($admin);

        return $admin;
    }

    private function detailUrl(Declaration $declaration): string
    {
        return '/admin/declaration/'.$declaration->getId()->toRfc4122();
    }

    private function confirmUrl(Declaration $declaration): string
    {
        return $this->detailUrl($declaration).'/confirm-manually';
    }

    private function reload(Declaration $declaration): Declaration
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloaded = $entityManager->getRepository(Declaration::class)->find($declaration->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * See AGENTS.md: the filter is armed by the request, so a helper reading after a
     * request to another tenant would find nothing.
     */
    private function disableTenantFilter(): void
    {
        $filters = self::getContainer()->get(EntityManagerInterface::class)->getFilters();

        if ($filters->isEnabled(OrganizationFilter::NAME)) {
            $filters->disable(OrganizationFilter::NAME);
        }
    }

    private function bodyText(): string
    {
        return $this->client->getCrawler()->filter('body')->text();
    }
}
