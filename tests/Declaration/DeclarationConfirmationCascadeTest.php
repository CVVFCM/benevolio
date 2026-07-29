<?php

declare(strict_types=1);

namespace App\Tests\Declaration;

use App\Entity\Declaration;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use Doctrine\ORM\EntityManagerInterface;
use Finite\StateMachine;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Both machines start in awaiting_confirmation, but only the declaration carries
 * the token — so something has to move the lines when it is confirmed.
 *
 * A missed cascade would not corrupt anything, which is exactly why it needs a
 * test: DeclarationTransitionGuard would simply refuse every verdict, and the
 * declaration would become quietly undecidable rather than visibly wrong.
 */
final class DeclarationConfirmationCascadeTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private StateMachine $stateMachine;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->stateMachine = self::getContainer()->get(StateMachine::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function a_line_starts_awaiting_confirmation_like_its_declaration(): void
    {
        $action = DeclarationActionFactory::new()->for(OrganizationFactory::createOne())->create();

        self::assertSame(DeclarationActionState::AWAITING_CONFIRMATION, $action->getState());
        self::assertSame(
            DeclarationState::AWAITING_CONFIRMATION,
            $action->getDeclaration()->getState(),
        );
    }

    /**
     * Three lines, not one: a cascade that only moved the first would pass a
     * single-line test and fail every real declaration.
     */
    #[Test]
    public function confirming_the_declaration_confirms_every_line(): void
    {
        $declaration = $this->declarationWithLines(3);

        $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_CONFIRM);
        $this->entityManager->flush();

        $reloaded = $this->reload($declaration->getId());
        self::assertSame(DeclarationState::SUBMITTED, $reloaded->getState());
        self::assertCount(3, $reloaded->getActions());
        foreach ($reloaded->getActions() as $action) {
            self::assertSame(DeclarationActionState::SUBMITTED, $action->getState());
        }
    }

    /**
     * The guard is what a missed cascade would trip, so prove the pairing rather
     * than just the states: unconfirmed lines mean no verdict is reachable.
     */
    #[Test]
    public function an_unconfirmed_line_cannot_be_validated(): void
    {
        $declaration = $this->declarationWithLines(1);
        $action = $declaration->getActions()->first();
        self::assertNotFalse($action);

        self::assertFalse(
            $this->stateMachine->can($action, DeclarationActionState::TRANSITION_VALIDATE),
        );
    }

    #[Test]
    public function awaiting_confirmation_is_not_decided(): void
    {
        self::assertFalse(DeclarationActionState::AWAITING_CONFIRMATION->isDecided());
        self::assertTrue(DeclarationActionState::AWAITING_CONFIRMATION->isAwaitingConfirmation());
        self::assertFalse(DeclarationActionState::SUBMITTED->isDecided());
        self::assertTrue(DeclarationActionState::VALIDATED->isDecided());
        self::assertTrue(DeclarationActionState::REFUSED->isDecided());
    }

    /**
     * Lines first, confirmation second — the order DeclarationSubmitter uses, and
     * the only order in which the cascade has anything to act on.
     */
    private function declarationWithLines(int $count): Declaration
    {
        $declaration = DeclarationFactory::createOne();
        DeclarationActionFactory::new()->forDeclaration($declaration)->many($count)->create();

        // Foundry's many()->create() leaves the inverse collection stale, so the
        // declaration in memory still believes it has no lines.
        return $this->reload($declaration->getId());
    }

    private function reload(Uuid $id): Declaration
    {
        $this->entityManager->clear();
        $declaration = $this->entityManager->getRepository(Declaration::class)->find($id);
        self::assertNotNull($declaration);

        return $declaration;
    }
}
