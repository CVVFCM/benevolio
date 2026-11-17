<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Security\AdminActionToken;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\Tests\Support\ReloadsEntities;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function sprintf;

/**
 * "Valider tout" through the real back-office, which is also what proves
 * DeclarationDecider is actually wired into the container — its unit test builds
 * it by hand.
 */
final class DeclarationDecisionTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReadsAdminPages;
    use ReloadsEntities;
    use ResetDatabase;

    private const string ACTIONS_URL = '/admin/declaration-action';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * An unconfirmed declaration is listed so the treasurer can see it exists —
     * a volunteer who says "I declared" should not be a mystery — but it offers no
     * verdict, and reaching the URL directly is refused.
     */
    #[Test]
    public function an_unconfirmed_declaration_is_visible_but_not_decidable(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->many(2)->create();
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', self::detailUrl('/admin/declaration', $declaration->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('En attente de confirmation', $this->bodyText());
        self::assertStringNotContainsString('Valider tout', $this->bodyText());

        // …and the URL is not a way round the missing button: with no button there
        // is no token to post, and DeclarationDeciderTest covers the refusal itself.
        $this->client->request('POST', $this->validateAllUrl($declaration));
        $this->client->followRedirect();
        self::assertStringContainsString('La page a expiré', $this->bodyText());
        self::assertTrue($this->reload($declaration)->getState()->isAwaitingConfirmation());
    }

    #[Test]
    public function validate_all_decides_the_declaration_and_all_its_lines(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 3);
        $this->loginAsAdminOf($organization);

        $this->submitButton(self::detailUrl('/admin/declaration', $declaration->getId()), $this->validateAllUrl($declaration));

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $reloaded = $this->reload($declaration);
        self::assertSame(DeclarationState::VALIDATED, $reloaded->getState());
        foreach ($reloaded->getActions() as $action) {
            self::assertSame(DeclarationActionState::VALIDATED, $action->getState());
        }
    }

    /**
     * The button must disappear once the verdict is in, rather than offering an
     * action that would now throw.
     */
    #[Test]
    public function the_button_is_gone_once_the_declaration_is_decided(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', self::detailUrl('/admin/declaration', $declaration->getId()));
        self::assertStringContainsString('Valider tout', $this->bodyText());

        $this->submitButton(self::detailUrl('/admin/declaration', $declaration->getId()), $this->validateAllUrl($declaration));
        $this->client->followRedirect();

        self::assertStringNotContainsString('Valider tout', $this->bodyText());
        self::assertStringNotContainsString('Refuser tout', $this->bodyText());
    }

    /**
     * A mixed basket is reported to the treasurer, not left to blow up. This is the
     * visible face of Declaration having its own state machine.
     */
    #[Test]
    public function a_mixed_basket_is_reported_and_changes_nothing(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 2);
        $this->loginAsAdminOf($organization);

        // Taken while the button is still offered: once the basket is mixed the
        // page no longer renders it, and a token is needed to get past the CSRF
        // check to the refusal this test is about.
        $token = $this->actionTokenOn(self::detailUrl('/admin/declaration', $declaration->getId()), $this->validateAllUrl($declaration));

        $this->submitButton(self::ACTIONS_URL, $this->actionUrl($this->lineAt($declaration, 0), 'validate'));
        $this->submitButton(self::ACTIONS_URL, $this->actionUrl($this->lineAt($declaration, 1), 'refuse'));

        // Neither bulk verdict applies now, so neither button is offered…
        $this->client->request('GET', self::detailUrl('/admin/declaration', $declaration->getId()));
        self::assertStringNotContainsString('Valider tout', $this->bodyText());

        // …and reaching the URL directly is refused rather than half-applied.
        $this->client->request('POST', $this->validateAllUrl($declaration), [AdminActionToken::FIELD => $token]);
        $this->client->followRedirect();

        self::assertStringContainsString('aucun verdict global ne s\'applique', $this->bodyText());
        self::assertSame(DeclarationState::SUBMITTED, $this->reload($declaration)->getState());
    }

    #[Test]
    public function a_single_line_can_be_validated_from_the_action_list(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 2);
        $this->loginAsAdminOf($organization);

        $this->submitButton(self::ACTIONS_URL, $this->actionUrl($this->lineAt($declaration, 0), 'validate'));

        self::assertResponseRedirects();

        // The line is decided, but the declaration waits for the other one.
        $reloaded = $this->reload($declaration);
        self::assertSame(DeclarationState::SUBMITTED, $reloaded->getState());
        self::assertTrue($reloaded->hasUndecidedAction());
    }

    /**
     * A verdict is a POST carrying a token bound to the row. As a GET link, any page
     * the treasurer had open — or a mail scanner following a link — could have
     * validated a declaration, and validated lines feed the tax receipts.
     */
    #[Test]
    public function a_verdict_cannot_be_given_by_a_link(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        foreach ([$this->validateAllUrl($declaration), self::detailUrl('/admin/declaration', $declaration->getId()).'/refuse-all'] as $url) {
            $this->client->request('GET', $url);
            self::assertFalse($this->client->getResponse()->isRedirection(), $url);
        }

        foreach (['validate', 'refuse'] as $transition) {
            $this->client->request('GET', $this->actionUrl($this->lineAt($declaration, 0), $transition));
            self::assertFalse($this->client->getResponse()->isRedirection(), $transition);
        }

        self::assertSame(DeclarationState::SUBMITTED, $this->reload($declaration)->getState());
        self::assertSame(DeclarationActionState::SUBMITTED, $this->lineAt($declaration, 0)->getState());
    }

    #[Test]
    public function a_verdict_posted_without_its_token_changes_nothing(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $this->client->request('POST', $this->validateAllUrl($declaration), [AdminActionToken::FIELD => 'forged']);
        $this->client->followRedirect();
        self::assertStringContainsString('La page a expiré', $this->bodyText());

        $this->client->request('POST', $this->actionUrl($this->lineAt($declaration, 0), 'refuse'), [AdminActionToken::FIELD => 'forged']);
        $this->client->followRedirect();
        self::assertStringContainsString('La page a expiré', $this->bodyText());

        self::assertSame(DeclarationState::SUBMITTED, $this->reload($declaration)->getState());
        self::assertSame(DeclarationActionState::SUBMITTED, $this->lineAt($declaration, 0)->getState());
    }

    /**
     * A token is bound to its row: one minted for a declaration does not decide another.
     */
    #[Test]
    public function a_token_minted_for_one_declaration_does_not_decide_another(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $first = $this->declarationWithLines($organization, 1);
        $second = $this->declarationWithLines($organization, 1);
        $this->loginAsAdminOf($organization);

        $token = $this->actionTokenOn(self::detailUrl('/admin/declaration', $first->getId()), $this->validateAllUrl($first));
        $this->client->request('POST', $this->validateAllUrl($second), [AdminActionToken::FIELD => $token]);

        self::assertSame(DeclarationState::SUBMITTED, $this->reload($second)->getState());
    }

    /**
     * Clears the entity manager before the request, which is NOT optional here.
     *
     * WebTestCase shares its container — and therefore its EntityManager — with
     * the requests it makes. Foundry's createMany() leaves the in-memory
     * Declaration with a stale `actions` collection (one element for three rows),
     * and without clearing, EasyAdmin's find() hands the controller that very
     * instance from the identity map. DeclarationDecider then "validates them all"
     * having seen only one, and the guard is satisfied by a collection that does
     * not match the database.
     *
     * A real request always gets a fresh EntityManager (Doctrine resets it between
     * requests, including under FrankenPHP worker mode), so this is a test-harness
     * artefact rather than a production hazard — but it makes the test lie.
     */
    private function declarationWithLines(Organization $organization, int $count): Declaration
    {
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->many($count)->create();

        $reloaded = $this->reload($declaration);
        self::assertCount($count, $reloaded->getActions());

        return $reloaded;
    }

    private function validateAllUrl(Declaration $declaration): string
    {
        return self::detailUrl('/admin/declaration', $declaration->getId()).'/validate-all';
    }

    /**
     * Presses a verdict button the way a browser does: through the form the page
     * renders, so the token posted is the real one.
     */
    private function submitButton(string $pageUrl, string $actionUrl): void
    {
        $crawler = $this->client->request('GET', $pageUrl);

        $this->client->submit($crawler->filter(sprintf('form[action="%s"]', $actionUrl))->form());
    }

    private function actionUrl(DeclarationAction $action, string $transition): string
    {
        return '/admin/declaration-action/'.$action->getId()->toRfc4122().'/'.$transition;
    }

    /**
     * Collection::toArray() offsets are not provably present to a static analyser,
     * so the assertion narrows the type where declarationWithLines() already
     * guaranteed the count.
     */
    private function lineAt(Declaration $declaration, int $index): DeclarationAction
    {
        $lines = array_values($this->reload($declaration)->getActions()->toArray());

        self::assertArrayHasKey($index, $lines);

        return $lines[$index];
    }
}
