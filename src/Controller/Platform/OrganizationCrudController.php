<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Entity\Organization;
use App\Organization\DefaultTasks;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CountryField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manages the tenants themselves. Only reachable from the platform dashboard.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Organization>
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OrganizationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly DefaultTasks $defaultTasks,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Organization::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // The translation domain is set once on the dashboard (EasyAdmin 5 has no
        // Crud::setTranslationDomain()), so these keys resolve against admin.fr.xlf.
        return $crud
            ->setEntityLabelInSingular('organization.label')
            ->setEntityLabelInPlural('organization.label_plural')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'organization.name');

        // The slug appears in the public volunteer URLs (/a/{slug}/…), so it is
        // shown as an editable field rather than silently regenerated: changing
        // it invalidates links already handed out to volunteers.
        yield SlugField::new('slug', 'organization.slug')
            ->setTargetFieldName('name')
            ->setHelp('organization.slug.help');

        yield BooleanField::new('active', 'organization.active')
            ->setHelp('organization.active.help');

        // What CERFA 2041-RD asks about the beneficiary. Without the SIREN/RNA a
        // receipt is not a valid document, so App\Receipt\ReceiptEligibility refuses
        // to issue one rather than leaving the line blank — see that class.
        yield FormField::addFieldset('organization.cerfa_fieldset')
            ->setHelp('organization.cerfa_fieldset.help');

        yield TextField::new('sirenOrRna', 'organization.siren_or_rna')
            ->setHelp('organization.siren_or_rna.help');

        yield TextareaField::new('objet', 'organization.objet')
            ->setHelp('organization.objet.help')
            ->hideOnIndex();

        yield TextField::new('addressNumber', 'organization.address_number')->hideOnIndex();
        yield TextField::new('addressStreet', 'organization.address_street')->hideOnIndex();
        yield TextField::new('addressPostcode', 'organization.address_postcode')->hideOnIndex();
        yield TextField::new('addressCity', 'organization.address_city')->hideOnIndex();
        yield CountryField::new('addressCountry', 'organization.address_country')->hideOnIndex();

        yield DateTimeField::new('createdAt', 'organization.created_at')
            ->onlyOnIndex();
    }

    /**
     * A brand-new association needs tasks or its public declaration form
     * has nothing to offer. One of the two explicit call sites of
     * DefaultTasks — see that class for why it is not a Doctrine listener.
     */
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);

        // $entityInstance is narrowed to Organization by the @extends generic.
        $this->defaultTasks->createFor($entityInstance);
        $entityManager->flush();
    }
}
