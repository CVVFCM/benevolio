<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\DemoAssociation;
use App\Demo\DemoReset;
use App\Demo\DemoResetReport;
use App\Demo\Exception\DemoResetRefusedException;
use App\Entity\Activity;
use App\Entity\Declaration;
use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\Receipt;
use App\Entity\Task;
use App\Entity\User;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\ReceiptFactory;
use App\Factory\UserFactory;
use App\Organization\DefaultTasks;
use App\Receipt\ReceiptStorage;
use App\Security\Role;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use App\Tests\Support\ReloadsEntities;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function array_map;
use function count;
use function ksort;
use function sort;

/**
 * The public demo, created and put back (#62). Its login is public, so a reset has to undo
 * whatever an admin can do — and must never reach anything but the demo.
 *
 * Hits real s3mock: the reset deletes the demo's receipt PDFs.
 */
final class DemoResetTest extends KernelTestCase
{
    use Factories;
    use ReloadsEntities;
    use ResetDatabase;

    #[Test]
    public function it_creates_the_demo_with_its_owner_and_a_sample(): void
    {
        $report = $this->reset();

        self::assertTrue($report->created);
        $organization = $this->demoOrganization();
        self::assertSame(DemoAssociation::NAME, $organization->getName());
        self::assertTrue($organization->isActive());
        self::assertFalse($organization->isListedOnHomepage());
        // A complete CERFA identity, so the demo can issue receipts.
        self::assertNotNull($organization->getPostalAddress());
        self::assertNotNull($organization->getSignature());

        $owner = $this->findOne(User::class, ['email' => DemoAssociation::ADMIN_EMAIL]);
        self::assertSame($organization->getId()->toRfc4122(), $owner->getOrganization()?->getId()->toRfc4122());
        self::assertTrue($owner->hasRole(Role::OWNER));
        self::assertTrue($this->passwordHasher()->isPasswordValid($owner, DemoAssociation::ADMIN_PASSWORD));

        self::assertSame($this->sortedDefaultTasks(), $this->taskNames($organization));
        self::assertCount(4, $this->findAllOf(Activity::class, ['organization' => $organization]));
        self::assertCount(2, $this->findAllOf(FiscalYear::class, ['organization' => $organization]));

        self::assertSame([
            'awaiting_confirmation' => 1,
            'submitted' => 2,
            'validated' => 2,
        ], $this->declarationStates($organization));
        self::assertSame([
            'awaiting_confirmation' => 1,
            'refused' => 1,
            'submitted' => 2,
            'validated' => 3,
        ], $this->lineStates($organization));

        // Confirming and ruling went through the use cases, and the demo mails nobody.
        self::assertEmailCount(0);
    }

    #[Test]
    public function it_undoes_what_visitors_did_and_keeps_the_association_row(): void
    {
        $this->reset();
        $organization = $this->demoOrganization();
        $id = $organization->getId()->toRfc4122();

        // What anyone holding the public login can do.
        $organization->setName('Escroquerie')->setListedOnHomepage(true);
        $owner = $this->findOne(User::class, ['email' => DemoAssociation::ADMIN_EMAIL]);
        $owner->setPassword($this->passwordHasher()->hashPassword($owner, 'locked-you-out'));
        $this->entityManager()->flush();
        UserFactory::new()->admin($organization)->invited()->create(['email' => 'invited@example.test']);
        $person = PersonFactory::new()->for($organization)->create();
        $declaration = DeclarationFactory::new()->forPerson($person)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create();

        $storage = self::getContainer()->get(ReceiptStorage::class);
        $path = $storage->store(2025, $person, '0001', '%PDF-1.4 demo');
        ReceiptFactory::createOne(['person' => $person, 'number' => '0001', 'storagePath' => $path]);

        $report = $this->reset();

        self::assertFalse($report->created);
        self::assertSame(1, $report->deletedReceipts);
        self::assertSame([], $report->undeletedReceiptPaths);

        $organization = $this->demoOrganization();
        self::assertSame($id, $organization->getId()->toRfc4122());
        self::assertSame(DemoAssociation::NAME, $organization->getName());
        self::assertFalse($organization->isListedOnHomepage());

        self::assertNull($this->findOneOrNull(User::class, ['email' => 'invited@example.test']));
        $owner = $this->findOne(User::class, ['email' => DemoAssociation::ADMIN_EMAIL]);
        self::assertTrue($this->passwordHasher()->isPasswordValid($owner, DemoAssociation::ADMIN_PASSWORD));
        self::assertTrue($owner->isOwner());

        self::assertCount(0, $this->findAllOf(Receipt::class, ['organization' => $organization]));
        self::assertCount(5, $this->findAllOf(Person::class, ['organization' => $organization]));
        self::assertCount(5, $this->findAllOf(Declaration::class, ['organization' => $organization]));
        self::assertSame($this->sortedDefaultTasks(), $this->taskNames($organization));

        try {
            $storage->read($path);
            self::fail('The receipt PDF survived the reset.');
        } catch (UnableToReadFile) {
        }
    }

    #[Test]
    public function it_leaves_every_other_association_alone(): void
    {
        $other = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        UserFactory::new()->admin($other)->create();
        $declaration = DeclarationFactory::new()->for($other)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create();

        $this->reset();
        $this->reset();

        self::assertCount(1, $this->findAllOf(Declaration::class, ['organization' => $other]));
        self::assertCount(1, $this->findAllOf(User::class, ['organization' => $other]));
        self::assertCount(count(DefaultTasks::NAMES), $this->findAllOf(Task::class, ['organization' => $other]));
    }

    /**
     * DEMO_ORGANIZATION_SLUG pointed at a real association must not wipe it.
     */
    #[Test]
    public function it_refuses_an_association_whose_accounts_are_not_the_demos(): void
    {
        $real = OrganizationFactory::createOne(['slug' => 'demo', 'name' => 'Une vraie association']);
        UserFactory::new()->admin($real)->create(['email' => 'tresorier@example.test']);
        $declaration = DeclarationFactory::new()->for($real)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create();

        try {
            $this->reset();
            self::fail('The reset wiped an association that is not the demo.');
        } catch (DemoResetRefusedException) {
        }

        self::assertSame('Une vraie association', $this->reload($real)->getName());
        self::assertCount(1, $this->findAllOf(Declaration::class, ['organization' => $real]));
    }

    #[Test]
    public function it_refuses_when_the_demo_login_belongs_to_another_association(): void
    {
        $elsewhere = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        UserFactory::new()->admin($elsewhere)->create(['email' => DemoAssociation::ADMIN_EMAIL]);

        $this->expectException(DemoResetRefusedException::class);

        $this->reset();
    }

    private function reset(): DemoResetReport
    {
        return self::getContainer()->get(DemoReset::class)->reset();
    }

    private function demoOrganization(): Organization
    {
        return $this->findOne(Organization::class, ['slug' => 'demo']);
    }

    /**
     * @return list<string>
     */
    private function sortedDefaultTasks(): array
    {
        $names = DefaultTasks::NAMES;
        sort($names);

        return $names;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    /**
     * @return list<string>
     */
    private function taskNames(Organization $organization): array
    {
        $names = array_map(static fn (Task $task): string => $task->getName(), $this->findAllOf(Task::class, ['organization' => $organization]));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, int>
     */
    private function declarationStates(Organization $organization): array
    {
        return $this->tally(array_map(
            static fn (Declaration $declaration): DeclarationState => $declaration->getState(),
            $this->findAllOf(Declaration::class, ['organization' => $organization]),
        ));
    }

    /**
     * @return array<string, int>
     */
    private function lineStates(Organization $organization): array
    {
        $states = [];
        foreach ($this->findAllOf(Declaration::class, ['organization' => $organization]) as $declaration) {
            foreach ($declaration->getActions() as $action) {
                $states[] = $action->getState();
            }
        }

        return $this->tally($states);
    }

    /**
     * @param list<DeclarationState|DeclarationActionState> $states
     *
     * @return array<string, int>
     */
    private function tally(array $states): array
    {
        $counts = [];
        foreach ($states as $state) {
            $counts[$state->value] = ($counts[$state->value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
