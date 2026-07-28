<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Entity\User;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manages back-office accounts across every tenant.
 *
 * The form binds a plaintext password to User::$plainPassword, which is not
 * persisted; persistEntity()/updateEntity() hash it and immediately clear it, so
 * the stored hash never round-trips through the form.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
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

        // Required when creating, optional when editing: leaving it empty on an
        // edit keeps the existing password.
        yield TextField::new('plainPassword', 'user.password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('required', Crud::PAGE_NEW === $pageName)
            ->setFormTypeOption('empty_data', null)
            ->setHelp('user.password.help')
            ->onlyOnForms();
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->hashPlainPassword($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->hashPlainPassword($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPlainPassword(object $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
        }

        $plainPassword = $entityInstance->getPlainPassword();

        if (null === $plainPassword) {
            return;
        }

        $entityInstance->setPassword($this->passwordHasher->hashPassword($entityInstance, $plainPassword));
        $entityInstance->erasePlainPassword();
    }
}
