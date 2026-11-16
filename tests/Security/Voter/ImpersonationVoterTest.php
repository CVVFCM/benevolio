<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Security\Voter\ImpersonationVoter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Who may be impersonated, and by whom: the `role` the firewall's switch_user checks.
 */
final class ImpersonationVoterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function a_super_admin_may_act_as_an_association_account(): void
    {
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $organization = OrganizationFactory::createOne();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($superAdmin, UserFactory::new()->admin($organization)->create()));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($superAdmin, UserFactory::new()->owner(OrganizationFactory::createOne())->create()));
    }

    #[Test]
    public function never_as_a_super_admin_nor_as_oneself(): void
    {
        $superAdmin = UserFactory::new()->superAdmin()->create();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($superAdmin, UserFactory::new()->superAdmin()->create()));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($superAdmin, $superAdmin));
    }

    #[Test]
    public function an_association_account_may_not_impersonate(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($owner, UserFactory::new()->admin($organization)->create()));
    }

    #[Test]
    public function not_from_inside_an_impersonation(): void
    {
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $first = UserFactory::new()->admin(OrganizationFactory::createOne())->create();
        $original = new UsernamePasswordToken($superAdmin, 'main', $superAdmin->getRoles());
        $switched = new SwitchUserToken($first, 'main', $first->getRoles(), $original);

        $vote = new ImpersonationVoter()->vote($switched, UserFactory::new()->admin(OrganizationFactory::createOne())->create(), [ImpersonationVoter::IMPERSONATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    private function vote(User $current, User $target): int
    {
        return new ImpersonationVoter()->vote(
            new UsernamePasswordToken($current, 'main', $current->getRoles()),
            $target,
            [ImpersonationVoter::IMPERSONATE],
        );
    }
}
