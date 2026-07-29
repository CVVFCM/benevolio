<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\TaskFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The hourly rate: where it comes from, and why a filed line keeps its own copy.
 *
 * Rates are stored in cents throughout. A rate multiplies hours that are summed in
 * integer hundredths, and ext-bcmath is not installed, so integers are the only way
 * that arithmetic stays exact.
 */
final class HourlyRateTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function a_task_without_its_own_rate_falls_back_to_the_association(): void
    {
        $organization = OrganizationFactory::createOne(['defaultHourlyRateCents' => 1500]);
        $task = TaskFactory::new()->for($organization)->create(['hourlyRateCents' => null]);

        self::assertNull($task->getHourlyRateCents());
        self::assertSame(1500, $task->resolveHourlyRateCents());
    }

    #[Test]
    public function a_task_with_its_own_rate_overrides_the_association(): void
    {
        $organization = OrganizationFactory::createOne(['defaultHourlyRateCents' => 1500]);
        $task = TaskFactory::new()->for($organization)->create(['hourlyRateCents' => 2500]);

        self::assertSame(2500, $task->resolveHourlyRateCents());
    }

    /**
     * Asserted against the constant, not against 1200: the figure is a starting
     * point the association is meant to change, so pinning the literal here would
     * only test that nobody edited the constant.
     */
    #[Test]
    public function an_organization_starts_with_the_documented_default(): void
    {
        self::assertSame(
            Organization::DEFAULT_HOURLY_RATE_CENTS,
            OrganizationFactory::createOne()->getDefaultHourlyRateCents(),
        );
    }

    #[Test]
    public function a_filed_line_snapshots_the_rate_in_force(): void
    {
        $organization = OrganizationFactory::createOne(['defaultHourlyRateCents' => 1500]);
        $task = TaskFactory::new()->for($organization)->create(['hourlyRateCents' => 2500]);
        $declaration = DeclarationFactory::new()->for($organization)->create();

        $action = DeclarationActionFactory::new()
            ->forDeclaration($declaration)
            ->create(['task' => $task]);

        self::assertSame(2500, $action->getHourlyRateCents());
    }

    /**
     * The whole reason the snapshot exists. A treasurer correcting a rate must not
     * silently rewrite the valuation of declarations already filed — possibly already
     * validated, possibly already in the books.
     */
    #[Test]
    public function changing_a_rate_afterwards_does_not_touch_a_filed_line(): void
    {
        $organization = OrganizationFactory::createOne(['defaultHourlyRateCents' => 1500]);
        $task = TaskFactory::new()->for($organization)->create(['hourlyRateCents' => 2500]);
        $declaration = DeclarationFactory::new()->for($organization)->create();
        $action = DeclarationActionFactory::new()
            ->forDeclaration($declaration)
            ->create(['task' => $task]);
        $actionId = $action->getId();

        $task->setHourlyRateCents(4000);
        $organization->setDefaultHourlyRateCents(9900);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(DeclarationAction::class)->find($actionId);
        self::assertNotNull($reloaded);
        self::assertSame(2500, $reloaded->getHourlyRateCents(), 'The filed rate moved with the task.');
        // And the task itself did change, so the test is not passing by accident.
        self::assertSame(4000, $reloaded->getTask()->getHourlyRateCents());
    }

    /**
     * A line filed under a task with no rate of its own still records a figure,
     * because the association's default is required. Nothing downstream has to cope
     * with an unpriced line.
     */
    #[Test]
    public function a_line_is_never_filed_without_a_rate(): void
    {
        $organization = OrganizationFactory::createOne(['defaultHourlyRateCents' => 1800]);
        $task = TaskFactory::new()->for($organization)->create(['hourlyRateCents' => null]);
        $declaration = DeclarationFactory::new()->for($organization)->create();

        $action = DeclarationActionFactory::new()
            ->forDeclaration($declaration)
            ->create(['task' => $task]);

        self::assertSame(1800, $action->getHourlyRateCents());
    }
}
