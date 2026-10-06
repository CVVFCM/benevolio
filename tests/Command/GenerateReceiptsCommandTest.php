<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Organization;
use App\Entity\Receipt;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The console path into the yearly run.
 *
 * It exists because the run generates a PDF and sends a mail per volunteer inside one
 * request, which a large association can outlast. What has to hold here is the thing CLI
 * gets wrong: **the Doctrine tenant filter is off**, so the command has to scope itself, and
 * a run for one association must not touch another's contributions.
 *
 * Needs real Gotenberg and s3mock, like everything that issues a receipt.
 */
final class GenerateReceiptsCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $command;

    protected function setUp(): void
    {
        // bootKernel()'s return value, not self::$kernel: the property is declared nullable
        // and Application's constructor is not.
        $kernel = self::bootKernel();

        $this->command = new CommandTester(
            new Application($kernel)->find('app:receipts:generate'),
        );
    }

    #[Test]
    public function it_issues_the_years_receipts_for_one_association(): void
    {
        $organization = $this->association('les-jardins');
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');

        $this->command->setInputs(['yes']);
        $this->command->execute(['year' => '2025', '--organization' => 'les-jardins']);

        $this->command->assertCommandIsSuccessful();
        $display = $this->command->getDisplay();
        self::assertStringContainsString('0001', $display);
        self::assertStringContainsString('43,25', $display);
        self::assertCount(1, $this->entityManager()->getRepository(Receipt::class)->findAll());
    }

    /**
     * Answering no is a legitimate way out, and it must leave nothing behind.
     */
    #[Test]
    public function declining_the_confirmation_issues_nothing(): void
    {
        $organization = $this->association('les-jardins');
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');

        $this->command->setInputs(['no']);
        $this->command->execute(['year' => '2025', '--organization' => 'les-jardins']);

        $this->command->assertCommandIsSuccessful();
        self::assertCount(0, $this->entityManager()->getRepository(Receipt::class)->findAll());
    }

    /**
     * A confirmation whose default is "no" answers itself under --no-interaction, so the
     * command would have reported success having issued nothing — the worst possible outcome
     * for something a deployment script may be relying on.
     */
    #[Test]
    public function a_non_interactive_run_needs_force(): void
    {
        $organization = $this->association('les-jardins');
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');

        $exitCode = $this->command->execute(
            ['year' => '2025', '--organization' => 'les-jardins'],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--force', $this->command->getDisplay());
        self::assertCount(0, $this->entityManager()->getRepository(Receipt::class)->findAll());
    }

    #[Test]
    public function force_issues_without_asking(): void
    {
        $organization = $this->association('les-jardins');
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');

        $this->command->execute(
            ['year' => '2025', '--organization' => 'les-jardins', '--force' => true],
            ['interactive' => false],
        );

        $this->command->assertCommandIsSuccessful();
        self::assertCount(1, $this->entityManager()->getRepository(Receipt::class)->findAll());
    }

    /**
     * Without --organization the filter would not scope anything and the run would cover
     * every association at once, so the command refuses rather than guessing.
     */
    #[Test]
    public function it_refuses_to_run_without_an_association(): void
    {
        $exitCode = $this->command->execute(['year' => '2025']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--organization', $this->command->getDisplay());
    }

    #[Test]
    public function it_refuses_an_unknown_association(): void
    {
        $exitCode = $this->command->execute(['year' => '2025', '--organization' => 'nope']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('nope', $this->command->getDisplay());
    }

    #[Test]
    public function it_refuses_something_that_is_not_a_year(): void
    {
        $this->association('les-jardins');

        $exitCode = $this->command->execute(['year' => 'l\'an dernier', '--organization' => 'les-jardins']);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * The tenant scoping, which nothing else enforces in CLI.
     */
    #[Test]
    public function it_never_receipts_another_associations_contributions(): void
    {
        $mine = $this->association('les-jardins');
        $theirs = $this->association('les-voiles');
        // Mine needs its own closed exercice, or the run is refused for want of one and never
        // reaches the question this test is about.
        FiscalYearFactory::new()->for($mine)->calendarYear(2025)
            ->withPublishedBareme()->closed()->create();
        $this->volunteerWithWaivedTravel($theirs, '2025-06-21');

        $this->command->setInputs(['yes']);
        $this->command->execute(['year' => '2025', '--organization' => 'les-jardins']);

        $this->command->assertCommandIsSuccessful();
        self::assertCount(0, $this->entityManager()->getRepository(Receipt::class)->findAll());
        self::assertNotSame($mine->getId(), $theirs->getId());
    }

    #[Test]
    public function an_association_without_a_siren_fails_rather_than_reporting_success(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->volunteerWithWaivedTravel($organization, '2025-06-21');

        $this->command->setInputs(['yes']);
        $exitCode = $this->command->execute(['year' => '2025', '--organization' => 'les-jardins']);

        // A failure, unlike a volunteer with nothing to receipt: something has to be fixed
        // before this can work at all, and a script calling it should notice.
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('SIREN', $this->command->getDisplay());
    }

    private function association(string $slug): Organization
    {
        return OrganizationFactory::new()->withCerfaIdentity()->withSignature()
            ->create(['slug' => $slug]);
    }

    private function volunteerWithWaivedTravel(Organization $organization, string $date): void
    {
        FiscalYearFactory::new()->for($organization)
            ->calendarYear((int) new DateTimeImmutable($date)->format('Y'))
            ->withPublishedBareme()
            ->closed()
            ->create();

        $person = PersonFactory::createOne(['organization' => $organization]);
        $declaration = DeclarationFactory::new()->forPerson($person)->confirmed()->create();

        DeclarationActionFactory::new()->forDeclaration($declaration)->validated()->create([
            'date' => new DateTimeImmutable($date),
            'workHours' => '2.00',
            'journeys' => 2,
            'distanceKm' => 34,
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
        ]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
