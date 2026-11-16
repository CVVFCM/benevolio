<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\Role;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Who a platform super-admin may act as — the `role` of the firewall's switch_user,
 * which Symfony checks against the target account.
 *
 * Association accounts only: an owner or an admin, attached to an organization. Never
 * another super-admin, and never oneself. Not from inside an impersonation either: a
 * chain of switches would leave nobody sure whose session it is.
 *
 * @extends Voter<string, User>
 */
final class ImpersonationVoter extends Voter
{
    public const string IMPERSONATE = 'IMPERSONATE_USER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::IMPERSONATE === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $current = $token->getUser();

        if (!$current instanceof User || $token instanceof SwitchUserToken) {
            return false;
        }

        // The stored roles, on purpose: ROLE_SUPER_ADMIN is never inherited.
        return in_array(Role::SUPER_ADMIN->value, $current->getRoles(), true)
            && !in_array(Role::SUPER_ADMIN->value, $subject->getRoles(), true)
            && null !== $subject->getOrganization()
            && !$subject->getId()->equals($current->getId());
    }
}
