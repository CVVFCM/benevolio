<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DeclarationAction;
use App\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Tenant check for DeclarationAction, which is the one business entity that does
 * NOT implement TenantAware — so OrganizationFilter does not scope it.
 *
 * DeclarationActionCrudController scopes its *index* query by joining the
 * declaration, but the detail, edit and delete pages fetch a single record by id
 * and never go through that query builder. Without this voter, an organization
 * admin who guessed or was linked a UUID would reach another tenant's action.
 *
 * Delete this whole class the day DeclarationAction gains an organization FK via
 * TenantAwareTrait — the filter would then cover all of it automatically.
 *
 * @extends Voter<string, DeclarationAction>
 */
final class DeclarationActionVoter extends Voter
{
    /**
     * The permission EasyAdmin checks through Crud::setEntityPermission().
     */
    public const string VIEW = 'VIEW_DECLARATION_ACTION';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof DeclarationAction;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // $subject is narrowed to DeclarationAction by supports() and by the
        // @extends generic above.
        // A platform super-admin resolves to no tenant by design, so there is
        // nothing to compare against — role is the whole check for them.
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $organization = $this->tenantContext->tryGetOrganization();

        if (null === $organization) {
            return false;
        }

        return $subject->getOrganization()->getId()->equals($organization->getId());
    }
}
