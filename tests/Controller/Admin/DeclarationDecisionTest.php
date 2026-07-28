<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * "Valider tout" through the real back-office, which is also what proves
 * DeclarationDecider is actually wired into the container — its unit test builds
 * it by hand.
 */
final class DeclarationDecisionTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function validate_all_decides_the_declaration_and_all_its_lines(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $declaration = $this->declarationWithLines($organization, 3);
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', $this->validateAllUrl($declaration));

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

        $this->client->request('GET', $this->detailUrl($declaration));
        self::assertStringContainsString('Valider tout', $this->bodyText());

        $this->client->request('GET', $this->validateAllUrl($declaration));
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

        $this->client->request('GET', $this->actionUrl($this->lineAt($declaration, 0), 'validate'));
        $this->client->request('GET', $this->actionUrl($this->lineAt($declaration, 1), 'refuse'));

        // Neither bulk verdict applies now, so neither button is offered…
        $this->client->request('GET', $this->detailUrl($declaration));
        self::assertStringNotContainsString('Valider tout', $this->bodyText());

        // …and reaching the URL directly is refused rather than half-applied.
        $this->client->request('GET', $this->validateAllUrl($declaration));
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

        $this->client->request('GET', $this->actionUrl($this->lineAt($declaration, 0), 'validate'));

        self::assertResponseRedirects();

        // The line is decided, but the declaration waits for the other one.
        $reloaded = $this->reload($declaration);
        self::assertSame(DeclarationState::SUBMITTED, $reloaded->getState());
        self::assertTrue($reloaded->hasUndecidedAction());
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
        $declaration = DeclarationFactory::new()->for($organization)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->many($count)->create();

        $reloaded = $this->reload($declaration);
        self::assertCount($count, $reloaded->getActions());

        return $reloaded;
    }

    private function loginAsAdminOf(Organization $organization): void
    {
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());
    }

    private function detailUrl(Declaration $declaration): string
    {
        return '/admin/declaration/'.$declaration->getId()->toRfc4122();
    }

    private function validateAllUrl(Declaration $declaration): string
    {
        return $this->detailUrl($declaration).'/validate-all';
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

    private function reload(Declaration $declaration): Declaration
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloaded = $entityManager->getRepository(Declaration::class)->find($declaration->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function bodyText(): string
    {
        return $this->client->getCrawler()->filter('body')->text();
    }
}
