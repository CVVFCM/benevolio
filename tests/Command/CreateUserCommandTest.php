<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The command that makes a fresh deployment reachable at all.
 *
 * Assert\NotCompromisedPassword would call haveibeenpwned on every run; it is off
 * in the test environment (config/packages/validator.yaml), which is why the
 * passwords below can be obviously fake.
 */
final class CreateUserCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string PASSWORD = 'correct horse battery staple';

    private CommandTester $command;

    protected function setUp(): void
    {
        // bootKernel()'s return value, not self::$kernel: the property is declared
        // nullable and Application's constructor is not.
        $kernel = self::bootKernel();

        $this->command = new CommandTester(
            new Application($kernel)->find('app:user:create'),
        );
    }

    #[Test]
    public function it_creates_an_association_administrator(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->entityManager()->clear();

        $this->command->setInputs([self::PASSWORD]);
        $this->command->execute([
            'email' => 'tresorier@les-jardins.test',
            '--organization' => 'les-jardins',
        ]);

        $this->command->assertCommandIsSuccessful();
        $user = $this->user('tresorier@les-jardins.test');
        self::assertTrue($user->hasRole(Role::ADMIN));
        self::assertNotNull($user->getOrganization());
        self::assertSame('les-jardins', $user->getOrganization()->getSlug());
    }

    /**
     * The point of the whole command: the account must actually be able to log in.
     */
    #[Test]
    public function the_stored_password_is_hashed_and_verifies(): void
    {
        $this->command->setInputs([self::PASSWORD]);
        $this->command->execute(['email' => 'patron@plateforme.test', '--role' => 'super-admin']);

        $user = $this->user('patron@plateforme.test');
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)
                ->isPasswordValid($user, self::PASSWORD),
        );
        self::assertNull($user->getPlainPassword());
    }

    #[Test]
    public function a_super_admin_is_attached_to_no_association(): void
    {
        $this->command->setInputs([self::PASSWORD]);
        $this->command->execute(['email' => 'patron@plateforme.test', '--role' => 'super-admin']);

        $user = $this->user('patron@plateforme.test');
        self::assertTrue($user->hasRole(Role::SUPER_ADMIN));
        self::assertNull($user->getOrganization());
    }

    /**
     * User's own Expression constraint allows this combination — it only guards an
     * admin without an association — so the refusal has to come from the command.
     * Left through, UserTenantResolver would scope the whole platform backoffice to
     * that one association.
     */
    #[Test]
    public function a_super_admin_cannot_be_given_an_association(): void
    {
        OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->entityManager()->clear();

        $exitCode = $this->command->execute([
            'email' => 'patron@plateforme.test',
            '--role' => 'super-admin',
            '--organization' => 'les-jardins',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(0, $this->entityManager()->getRepository(User::class)->findAll());
    }

    #[Test]
    public function an_association_administrator_without_an_association_is_refused(): void
    {
        $this->command->setInputs([self::PASSWORD]);

        $exitCode = $this->command->execute(['email' => 'orphelin@les-jardins.test']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('rattaché à une association', $this->display());
        self::assertCount(0, $this->entityManager()->getRepository(User::class)->findAll());
    }

    #[Test]
    public function an_unknown_association_is_refused(): void
    {
        $exitCode = $this->command->execute([
            'email' => 'tresorier@ailleurs.test',
            '--organization' => 'nexiste-pas',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('nexiste-pas', $this->display());
    }

    #[Test]
    public function an_unknown_role_is_refused(): void
    {
        $exitCode = $this->command->execute(['email' => 'qui@plateforme.test', '--role' => 'sorcier']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('sorcier', $this->display());
    }

    #[Test]
    public function a_password_below_the_minimum_length_is_refused(): void
    {
        $this->command->setInputs(['court']);

        $exitCode = $this->command->execute(['email' => 'patron@plateforme.test', '--role' => 'super-admin']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(0, $this->entityManager()->getRepository(User::class)->findAll());
    }

    /**
     * SymfonyStyle hard-wraps its blocks to the terminal width, so a message can
     * be split mid-sentence by a newline and a run of padding. Collapse that back
     * before matching, or the assertions would depend on the column the wrap fell.
     */
    private function display(): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $this->command->getDisplay()));
    }

    private function user(string $email): User
    {
        $this->entityManager()->clear();
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
