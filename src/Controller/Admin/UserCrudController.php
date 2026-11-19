<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RequiresActionToken;
use App\Security\Role;
use App\Security\Voter\UserVoter;
use App\Tenant\TenantContext;
use App\User\LoginLinkMailer;
use App\User\OwnerAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

/**
 * The association's own back-office accounts: « Administrateurs ».
 *
 * Nobody types a password here. Creating an account takes an email address only,
 * and LoginLinkMailer sends its holder a link to choose their own. The first account
 * of an association is its owner (OwnerAssigner); owner and admins have the same
 * rights, except that only the owner deletes — see UserVoter.
 *
 * TENANT SCOPING, by hand. User is deliberately not TenantAware (the tenant filter
 * would break the user provider), so like DeclarationActionCrudController this
 * controller scopes every entry point itself:
 *   1. createIndexQueryBuilder() — the index and autocomplete;
 *   2. Crud::setEntityPermission(UserVoter::VIEW) — detail and delete;
 *   3. the custom action and deleteEntity() check the voter themselves, because
 *      setEntityPermission() does not cover custom actions.
 * tests/Controller/Admin/UserIsolationTest covers all three.
 *
 * Distinct from App\Controller\Platform\UserCrudController, which manages every
 * account across tenants and is denied on this dashboard.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    /** Suffixed with the account id, so a token minted for one cannot act on another. */
    private const string ACTION_RESEND_INVITATION = 'resendInvitation';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserRepository $users,
        private readonly OwnerAssigner $ownerAssigner,
        private readonly LoginLinkMailer $loginLinkMailer,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Administrateur')
            ->setEntityLabelInPlural('Administrateurs')
            ->setDefaultSort(['email' => 'ASC'])
            ->setSearchFields(['email'])
            ->setHelp(Crud::PAGE_NEW, 'Un lien lui sera envoyé par courriel pour choisir son mot de passe. Personne d\'autre ne le connaîtra.')
            // Single-record pages fetch by id and never see the query builder
            // below. See the class docblock.
            ->setEntityPermission(UserVoter::VIEW)
            // Inlined: the dropdown renders its own <form> and ignores an action's
            // template, so « Renvoyer l'invitation » would post without its CSRF token
            // and always answer « La page a expiré ».
            ->showEntityActionsInlined();
    }

    /**
     * The tenant scope for every listing of accounts.
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        return $this->users->scopeToOrganization(
            parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters),
            'entity',
            $this->tenantContext->getOrganization(),
        );
    }

    public function configureActions(Actions $actions): Actions
    {
        $resend = Action::new(self::ACTION_RESEND_INVITATION, 'Renvoyer l\'invitation', 'fa fa-paper-plane')
            ->linkToCrudAction(self::ACTION_RESEND_INVITATION)
            ->renderAsForm()
            ->setTemplatePath('admin/action/post_with_token.html.twig')
            ->displayIf(fn (User $user): bool => $this->isGranted(UserVoter::RESEND_INVITATION, $user));

        return $actions
            // No edit: the email is the login, and nothing else on an account is
            // the association's to change. No batch delete: a second road to
            // deleting, which the per-account rules (UserVoter::DELETE) would have
            // to be enforced on twice.
            ->disable(Action::EDIT, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $resend)
            ->add(Crud::PAGE_DETAIL, $resend)
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action): Action => $action
                ->displayIf(fn (User $user): bool => $this->isGranted(UserVoter::DELETE, $user)))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, fn (Action $action): Action => $action
                ->displayIf(fn (User $user): bool => $this->isGranted(UserVoter::DELETE, $user)));
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'Adresse électronique');

        // A template, not formatValue(): the value is an array, which TextField
        // refuses outright and the generic field prints as raw role names.
        yield Field::new('roles', 'Rôle')
            ->setTemplatePath('admin/field/user_role.html.twig')
            // Sorting a JSON array of role names orders nothing a reader means.
            ->setSortable(false)
            ->hideOnForm();

        // NOT a field on `password`: EasyAdmin's text template prints a field's raw value
        // in a `title` attribute beside the formatted one, so the hash was in the page
        // source of every account listed. The status reads hasPassword() — a boolean —
        // through its own template, and no field is bound to the hash at all.
        yield Field::new('hasPassword', 'État')
            ->setTemplatePath('admin/field/user_status.html.twig')
            ->setSortable(false)
            ->hideOnForm();
    }

    /**
     * Born attached to the current association and as an admin, before the form is
     * bound — so validation sees the account it will actually persist.
     */
    public function createEntity(string $entityFqcn): User
    {
        $user = new User();
        $user->setOrganization($this->tenantContext->getOrganization());
        $user->grant(Role::ADMIN);

        return $user;
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        // Only the first account of an association; normally the owner already
        // exists by the time anyone reaches this page.
        $this->ownerAssigner->assignIfFirst($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);

        // After the flush: a mail about an account that failed to save would be a
        // lie. The reverse — saved, mail lost — is what « Renvoyer l'invitation »
        // is for.
        $this->loginLinkMailer->sendInvitation($entityInstance);

        $this->addFlash('success', sprintf('Invitation envoyée à %s.', $entityInstance->getEmail()));
    }

    /**
     * Re-checked here: the delete button is hidden by the voter, but EasyAdmin's
     * delete endpoint takes a hand-built POST like any other.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$this->isGranted(UserVoter::DELETE, $entityInstance)) {
            throw new AccessDeniedException('This account cannot be deleted by the current user.');
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    /**
     * @param AdminContext<User> $context
     */
    #[RequiresActionToken]
    #[AdminRoute(path: '/{entityId}/resend-invitation', name: 'resend_invitation', options: ['methods' => ['POST']])]
    public function resendInvitation(AdminContext $context): Response
    {
        // setEntityPermission() does not cover custom actions: EasyAdmin hands over
        // a null instance instead of refusing. See DeclarationActionCrudController.
        $entity = $context->getEntity();

        if (!$entity->isAccessible()) {
            throw new AccessDeniedException('This account belongs to another organization.');
        }

        $user = $entity->getInstance();

        if (!$user instanceof User) {
            throw new NotFoundHttpException('Account not found.');
        }

        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

        if (!$this->isGranted(UserVoter::RESEND_INVITATION, $user)) {
            $this->addFlash('danger', sprintf('%s a déjà choisi son mot de passe : il peut utiliser « Mot de passe oublié ».', $user->getEmail()));
        } else {
            $this->loginLinkMailer->sendInvitation($user);
            $this->addFlash('success', sprintf('Invitation renvoyée à %s.', $user->getEmail()));
        }

        return new RedirectResponse(
            $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl(),
        );
    }
}
