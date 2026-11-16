<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Tenant\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Who may see, re-invite and delete a back-office account from /admin.
 *
 * User is not TenantAware — the tenant filter must never touch the user provider,
 * which runs before any tenant is known — so, like DeclarationActionVoter, this is
 * what keeps one association out of another's accounts on the single-record pages.
 * App\Controller\Admin\UserCrudController scopes its index query itself.
 *
 * Owner and admin have the same rights except one: only the owner deletes, never
 * the owner account itself, and nobody deletes their own account.
 *
 * Only for /admin. /platform is ROLE_SUPER_ADMIN and manages every account.
 *
 * VIEW also answers for NO account: EasyAdmin checks the entity permission on the
 * « new » page before any instance exists, and abstaining there is a 403 on the one
 * page this CRUD exists for. Creation is safe without an instance because the
 * controller attaches the new account to the current association itself.
 *
 * @extends Voter<string, User|null>
 */
final class UserVoter extends Voter
{
    /** The permission EasyAdmin checks through Crud::setEntityPermission(). */
    public const string VIEW = 'VIEW_USER';
    public const string RESEND_INVITATION = 'RESEND_USER_INVITATION';
    public const string DELETE = 'DELETE_USER';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (self::VIEW === $attribute && null === $subject) {
            return true;
        }

        return $subject instanceof User
            && in_array($attribute, [self::VIEW, self::RESEND_INVITATION, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $current = $token->getUser();

        if (!$current instanceof User) {
            return false;
        }

        if (null === $subject) {
            return null !== $this->tenantContext->tryGetOrganization();
        }

        if (!$this->belongsToCurrentTenant($subject)) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            // Once a password is set the link would only log them out of nothing;
            // « Mot de passe oublié » is the way back in for an active account.
            self::RESEND_INVITATION => !$subject->hasPassword(),
            self::DELETE => $current->isOwner()
                && !$subject->isOwner()
                && !$subject->getId()->equals($current->getId()),
            default => false,
        };
    }

    private function belongsToCurrentTenant(User $subject): bool
    {
        $tenant = $this->tenantContext->tryGetOrganization();
        $organization = $subject->getOrganization();

        return null !== $tenant
            && null !== $organization
            && $organization->getId()->equals($tenant->getId());
    }
}
