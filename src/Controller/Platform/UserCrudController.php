<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Entity\User;
use App\Security\Role;
use App\User\LoginLinkMailer;
use App\User\OwnerAssigner;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manages back-office accounts across every tenant.
 *
 * No password field: like an association's own « Administrateurs » page, creating
 * an account mails its holder a login link to choose their own (LoginLinkMailer).
 * The first account of an association becomes its owner (OwnerAssigner). The roles
 * checkbox does offer « Responsable », so a super-admin can repair ownership by
 * hand; App\Validator\OneOwnerPerOrganization refuses a second.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly OwnerAssigner $ownerAssigner,
        private readonly LoginLinkMailer $loginLinkMailer,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // The translation domain is set once on the dashboard (EasyAdmin 5 has no
        // Crud::setTranslationDomain()), so these keys resolve against admin.fr.xlf.
        return $crud
            ->setEntityLabelInSingular('user.label')
            ->setEntityLabelInPlural('user.label_plural')
            ->setDefaultSort(['email' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'user.email');

        yield ChoiceField::new('roles', 'user.roles')
            ->setChoices(Role::choices())
            ->allowMultipleChoices()
            ->renderExpanded();

        // Null for a super-admin, required for an organization admin — enforced
        // by the Assert\Expression constraints on App\Entity\User.
        yield AssociationField::new('organization', 'user.organization')
            ->setRequired(false)
            ->autocomplete()
            ->setHelp('user.organization.help');

        yield TextField::new('password', 'user.status')
            ->formatValue(static fn (mixed $value, User $user): string => $user->hasPassword()
                ? 'Actif'
                : 'Invitation en attente')
            // Sorting would order accounts by password hash.
            ->setSortable(false)
            ->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->ownerAssigner->assignIfFirst($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);

        // After the flush, as in App\Controller\Admin\UserCrudController.
        $this->loginLinkMailer->sendInvitation($entityInstance);
    }
}
