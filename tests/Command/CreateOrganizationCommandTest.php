<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Organization;
use App\Entity\Task;
use App\Factory\OrganizationFactory;
use App\Organization\DefaultTasks;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function count;

/**
 * This command is the third way an Organization can be created, so it is also the
 * third place the default tasks can be forgotten — which is what
 * App\Organization\DefaultTasks documents as the cost of not using a Doctrine
 * listener. The first test below is the one that would catch that.
 */
final class CreateOrganizationCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $command;

    protected function setUp(): void
    {
        // bootKernel()'s return value, not self::$kernel: the property is declared
        // nullable and Application's constructor is not.
        $kernel = self::bootKernel();

        $this->command = new CommandTester(
            new Application($kernel)->find('app:organization:create'),
        );
    }

    #[Test]
    public function it_creates_an_association_with_its_default_types(): void
    {
        $this->command->execute(['name' => 'Les Jardins Partagés', 'slug' => 'les-jardins']);

        $this->command->assertCommandIsSuccessful();

        $organization = $this->entityManager()->getRepository(Organization::class)
            ->findOneBy(['slug' => 'les-jardins']);
        self::assertNotNull($organization);
        self::assertSame('Les Jardins Partagés', $organization->getName());

        $types = $this->entityManager()->getRepository(Task::class)
            ->findBy(['organization' => $organization]);
        self::assertCount(count(DefaultTasks::NAMES), $types);
    }

    #[Test]
    public function the_public_form_url_is_printed_so_it_can_be_handed_over(): void
    {
        $this->command->execute(['name' => 'Les Jardins Partagés', 'slug' => 'les-jardins']);

        self::assertStringContainsString('/a/les-jardins/declaration', $this->command->getDisplay());
    }

    #[Test]
    public function a_slug_that_is_not_url_safe_is_refused(): void
    {
        $exitCode = $this->command->execute(['name' => 'Les Jardins', 'slug' => 'Les Jardins']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('minuscules', $this->command->getDisplay());
        self::assertCount(0, $this->entityManager()->getRepository(Organization::class)->findAll());
    }

    #[Test]
    public function a_slug_already_taken_is_refused(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->entityManager()->clear();

        $exitCode = $this->command->execute(['name' => 'Un homonyme', 'slug' => 'les-jardins']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(1, $this->entityManager()->getRepository(Organization::class)->findAll());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
