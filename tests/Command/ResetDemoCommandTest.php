<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Demo\DemoAssociation;
use App\Entity\Organization;
use App\Tests\Support\ReloadsEntities;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The CronJob's entry point. What matters is that an unattended run either resets or fails
 * loudly — never exits 0 having done nothing.
 */
final class ResetDemoCommandTest extends KernelTestCase
{
    use Factories;
    use ReloadsEntities;
    use ResetDatabase;

    private CommandTester $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->command = new CommandTester(new Application($kernel)->find('app:demo:reset'));
    }

    #[Test]
    public function the_cronjobs_invocation_creates_the_demo(): void
    {
        $this->command->execute(['--force' => true], ['interactive' => false]);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('créée', $this->command->getDisplay());
        self::assertSame(DemoAssociation::NAME, $this->findOne(Organization::class, ['slug' => 'demo'])->getName());
    }

    #[Test]
    public function an_unattended_run_without_force_fails_and_resets_nothing(): void
    {
        $this->command->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        self::assertNull($this->findOneOrNull(Organization::class, ['slug' => 'demo']));
    }

    #[Test]
    public function declining_the_confirmation_resets_nothing(): void
    {
        $this->command->setInputs(['no']);
        $this->command->execute([]);

        $this->command->assertCommandIsSuccessful();
        self::assertNull($this->findOneOrNull(Organization::class, ['slug' => 'demo']));
    }

    #[Test]
    public function a_refusal_is_a_failure(): void
    {
        $this->command->execute(['--force' => true], ['interactive' => false]);
        // Somebody else now holds the slug's only account: no longer the demo.
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE app_user SET email = ? WHERE email = ?',
            ['tresorier@example.test', DemoAssociation::ADMIN_EMAIL],
        );

        $this->command->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        self::assertStringContainsString('ne ressemble pas à la démo', $this->command->getDisplay());
    }
}
