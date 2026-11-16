<?php

declare(strict_types=1);

namespace App\Tests\User;

use App\Entity\Organization;
use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Security\Role;
use App\User\OwnerAssigner;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * "The first admin of an association is its owner", and nothing makes a second.
 */
final class OwnerAssignerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function the_first_account_of_an_association_becomes_its_owner(): void
    {
        $organization = OrganizationFactory::createOne();
        $user = $this->newAdmin($organization);

        $this->assigner()->assignIfFirst($user);

        self::assertTrue($user->isOwner());
        // Still an admin in its own right, not only through the hierarchy.
        self::assertTrue($user->hasRole(Role::ADMIN));
    }

    #[Test]
    public function a_later_account_stays_an_admin(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->owner($organization)->create();
        $user = $this->newAdmin($organization);

        $this->assigner()->assignIfFirst($user);

        self::assertFalse($user->isOwner());
    }

    /**
     * Ownership is per association: another association's owner is irrelevant.
     */
    #[Test]
    public function another_associations_owner_does_not_count(): void
    {
        UserFactory::new()->owner(OrganizationFactory::createOne())->create();
        $user = $this->newAdmin(OrganizationFactory::createOne());

        $this->assigner()->assignIfFirst($user);

        self::assertTrue($user->isOwner());
    }

    #[Test]
    public function a_platform_super_admin_is_never_an_owner(): void
    {
        $user = new User();
        $user->setEmail('patron@plateforme.test');
        $user->grant(Role::SUPER_ADMIN);

        $this->assigner()->assignIfFirst($user);

        self::assertFalse($user->isOwner());
    }

    #[Test]
    public function a_second_owner_is_refused_by_validation(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->owner($organization)->create(['email' => 'premier@asso.test']);

        $intruder = $this->newAdmin($organization);
        $intruder->addRole(Role::OWNER);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($intruder);

        self::assertCount(1, $violations);
        self::assertStringContainsString('premier@asso.test', (string) $violations->get(0)->getMessage());
    }

    #[Test]
    public function the_owner_itself_validates(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create();

        self::assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($owner));
    }

    #[Test]
    public function an_owner_must_belong_to_an_association(): void
    {
        $user = new User();
        $user->setEmail('flottant@asso.test');
        $user->grant(Role::ADMIN, Role::OWNER);

        $messages = array_map(
            static fn ($violation): string => (string) $violation->getMessage(),
            iterator_to_array(self::getContainer()->get(ValidatorInterface::class)->validate($user)),
        );

        self::assertContains('Le responsable d\'une association doit y être rattaché.', $messages);
    }

    private function newAdmin(Organization $organization): User
    {
        $user = new User();
        $user->setEmail(uniqid('admin', true).'@asso.test');
        $user->grant(Role::ADMIN);
        $user->setOrganization($organization);

        return $user;
    }

    private function assigner(): OwnerAssigner
    {
        return self::getContainer()->get(OwnerAssigner::class);
    }
}
