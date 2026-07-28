<?php

declare(strict_types=1);

namespace App\Tests\Declaration;

use App\Declaration\DeclarationDecider;
use App\Declaration\Exception\DeclarationNotDecidableException;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Doctrine\ORM\EntityManagerInterface;
use Finite\StateMachine;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Declaration and DeclarationAction each have their own state machine, so the
 * interesting behaviour is not "does a transition work" but "can the two
 * disagree". App\State\Listener\DeclarationTransitionGuard is what stops them,
 * and this is where that is proven.
 */
final class DeclarationDeciderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;
    private DeclarationDecider $decider;
    private StateMachine $stateMachine;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->stateMachine = self::getContainer()->get(StateMachine::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Built by hand rather than fetched: nothing injects DeclarationDecider
        // yet, so the container inlines it away. Its wiring is covered by the
        // back-office functional test instead.
        $this->decider = new DeclarationDecider($this->stateMachine, $this->entityManager);
    }

    #[Test]
    public function validate_all_decides_every_line_and_then_the_declaration(): void
    {
        $declaration = $this->declarationWithActions(3);

        self::assertTrue($this->decider->canValidateAll($declaration));
        $this->decider->validateAll($declaration);

        self::assertSame(DeclarationState::VALIDATED, $declaration->getState());
        foreach ($declaration->getActions() as $action) {
            self::assertSame(DeclarationActionState::VALIDATED, $action->getState());
        }
    }

    #[Test]
    public function refuse_all_is_the_only_way_the_declaration_reaches_refused(): void
    {
        $declaration = $this->declarationWithActions(2);

        self::assertTrue($this->decider->canRefuseAll($declaration));
        $this->decider->refuseAll($declaration);

        self::assertSame(DeclarationState::REFUSED, $declaration->getState());
        foreach ($declaration->getActions() as $action) {
            self::assertSame(DeclarationActionState::REFUSED, $action->getState());
        }
    }

    /**
     * The guard, seen directly: the whole-declaration verdict is unreachable while
     * a single line is still undecided.
     */
    #[Test]
    public function the_guard_blocks_the_declaration_verdict_while_a_line_is_undecided(): void
    {
        $declaration = $this->declarationWithActions(2);

        self::assertFalse($this->stateMachine->can($declaration, DeclarationState::TRANSITION_VALIDATE));

        // Decide one line only — still blocked.
        $this->stateMachine->apply(
            $this->actionAt($declaration, 0),
            DeclarationActionState::TRANSITION_VALIDATE,
        );
        self::assertFalse($this->stateMachine->can($declaration, DeclarationState::TRANSITION_VALIDATE));

        // Decide the last one — now allowed.
        $this->stateMachine->apply(
            $this->actionAt($declaration, 1),
            DeclarationActionState::TRANSITION_VALIDATE,
        );
        self::assertTrue($this->stateMachine->can($declaration, DeclarationState::TRANSITION_VALIDATE));
    }

    /**
     * The accepted trade-off of a global verdict, asserted so nobody "fixes" it by
     * weakening the guard: a genuinely mixed basket — one line validated, one
     * refused — has no terminal state and stays *soumise*.
     */
    #[Test]
    public function a_mixed_basket_leaves_the_declaration_submitted(): void
    {
        $declaration = $this->declarationWithActions(2);
        $this->stateMachine->apply($this->actionAt($declaration, 0), DeclarationActionState::TRANSITION_VALIDATE);
        $this->stateMachine->apply($this->actionAt($declaration, 1), DeclarationActionState::TRANSITION_REFUSE);

        self::assertFalse($this->decider->canValidateAll($declaration));
        self::assertFalse($this->decider->canRefuseAll($declaration));

        try {
            $this->decider->validateAll($declaration);
            self::fail('Expected a mixed basket to be refused.');
        } catch (DeclarationNotDecidableException) {
            // expected
        }

        self::assertSame(DeclarationState::SUBMITTED, $declaration->getState());
        // Nothing was half-applied: the earlier verdicts are untouched.
        self::assertSame(DeclarationActionState::VALIDATED, $this->actionAt($declaration, 0)->getState());
        self::assertSame(DeclarationActionState::REFUSED, $this->actionAt($declaration, 1)->getState());
    }

    /**
     * Only ONE line refused is not a mixed basket: refusing the rest is still a
     * coherent verdict for the whole declaration.
     */
    #[Test]
    public function a_partially_refused_declaration_can_still_be_refused_as_a_whole(): void
    {
        $declaration = $this->declarationWithActions(2);
        $this->stateMachine->apply($this->actionAt($declaration, 0), DeclarationActionState::TRANSITION_REFUSE);

        self::assertFalse($this->decider->canValidateAll($declaration));
        self::assertTrue($this->decider->canRefuseAll($declaration));

        $this->decider->refuseAll($declaration);

        self::assertSame(DeclarationState::REFUSED, $declaration->getState());
    }

    #[Test]
    public function a_declaration_without_a_line_cannot_be_decided(): void
    {
        $declaration = DeclarationFactory::createOne();

        self::assertFalse($this->decider->canValidateAll($declaration));
        self::assertFalse($this->stateMachine->can($declaration, DeclarationState::TRANSITION_VALIDATE));

        $this->expectException(DeclarationNotDecidableException::class);
        $this->decider->validateAll($declaration);
    }

    #[Test]
    public function an_already_decided_declaration_cannot_be_decided_again(): void
    {
        $declaration = $this->declarationWithActions(1);
        $this->decider->validateAll($declaration);

        self::assertFalse($this->decider->canValidateAll($declaration));
        self::assertFalse($this->decider->canRefuseAll($declaration));

        $this->expectException(DeclarationNotDecidableException::class);
        $this->decider->validateAll($declaration);
    }

    #[Test]
    public function totals_are_summed_exactly_across_lines(): void
    {
        $declaration = DeclarationFactory::createOne();
        DeclarationActionFactory::createOne([
            'declaration' => $declaration,
            'workHours' => '2.25',
            'distanceKm' => 10,
            'journeys' => 2,
        ]);
        DeclarationActionFactory::createOne([
            'declaration' => $declaration,
            'workHours' => '3.50',
            'distanceKm' => 7,
            'journeys' => 4,
        ]);

        // Reload for the same reason as declarationWithActions(): the in-memory
        // inverse collection cannot be trusted after Foundry created the lines.
        $id = $declaration->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Declaration::class)->find($id);
        self::assertNotNull($reloaded);

        // 2.25 + 3.50 with no floating point drift.
        self::assertSame('5.75', $reloaded->getTotalWorkHours());
        // One-way distance × journeys, per line: 10×2 + 7×4.
        self::assertSame(48, $reloaded->getTotalDistanceKm());
    }

    /**
     * Re-fetches the declaration after creating its lines.
     *
     * Necessary, not decorative: Foundry's createMany() leaves the inverse
     * `actions` collection of the in-memory Declaration stale (it reported one
     * element for two created rows). Reloading also matches what the back-office
     * actually does — it loads a declaration and its lines from the database.
     */
    private function declarationWithActions(int $count): Declaration
    {
        $declaration = DeclarationFactory::createOne();
        DeclarationActionFactory::createMany($count, ['declaration' => $declaration]);

        $id = $declaration->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Declaration::class)->find($id);

        self::assertNotNull($reloaded);
        self::assertCount($count, $reloaded->getActions());

        return $reloaded;
    }

    /**
     * Collection::first()/last() return false on an empty collection, which the
     * state machine cannot take. declarationWithActions() has already asserted the
     * count, so this narrows the type without hiding a real problem.
     */
    private function actionAt(Declaration $declaration, int $index): DeclarationAction
    {
        $actions = array_values($declaration->getActions()->toArray());

        self::assertArrayHasKey($index, $actions);

        return $actions[$index];
    }
}
