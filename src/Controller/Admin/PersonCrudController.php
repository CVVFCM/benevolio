<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Person;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The volunteers of the current association.
 *
 * Person is TenantAware, so OrganizationFilter scopes every query here — nothing
 * in this class has to think about tenancy. Contrast with
 * DeclarationActionCrudController.
 *
 * CONVENTION EXCEPTION: extends AbstractCrudController, hence AbstractController.
 * See App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Person>
 */
#[IsGranted('ROLE_ADMIN')]
final class PersonCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Person::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Bénévole')
            ->setEntityLabelInPlural('Bénévoles')
            ->setDefaultSort(['lastName' => 'ASC', 'firstName' => 'ASC'])
            ->setSearchFields(['firstName', 'lastName', 'email']);
    }

    /**
     * People are created by the public declaration form, never by hand: a Person
     * invented here would have no declaration and no way to be matched to one,
     * since matching is by email.
     */
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('lastName', 'Nom');
        yield TextField::new('firstName', 'Prénom');
        yield EmailField::new('email', 'Adresse électronique');

        yield TextField::new('address', 'Adresse')
            // Address is a value object with __toString(); it is not editable field
            // by field here, because the volunteer restates it on every declaration.
            ->onlyOnDetail();

        yield IntegerField::new('declarations.count', 'Déclarations')
            ->onlyOnIndex();

        yield DateTimeField::new('createdAt', 'Première déclaration')
            ->hideOnForm();
    }
}
