<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organization;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CountryField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The association's own record: the identity a CERFA is issued in, and its signature.
 *
 * It exists because everything the form 2041-RD asks about the beneficiary used to be
 * editable only from /platform, by a super-admin. An association could not fix its own
 * SIREN, let alone upload a signature — so every unsigned receipt meant an email to
 * someone else.
 *
 * **THIS IS THE ONLY CRUD UNDER /admin ON AN ENTITY THAT IS NOT TenantAware**, and
 * therefore the only one the Doctrine filter does not protect: Organization *is* the
 * tenant, so App\Doctrine\Filter\OrganizationFilter has nothing to scope here and another
 * association's UUID in the URL would otherwise be perfectly editable. Every entry point
 * below checks the instance against the tenant. The same trap, from the other side, is
 * documented in App\Controller\Admin\DeclarationActionCrudController.
 *
 * One record, so there is no index and nothing to create or delete. The menu points
 * straight at the tenant's own detail page — see App\Controller\Admin\DashboardController.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Organization>
 */
#[IsGranted('ROLE_ADMIN')]
final class MyOrganizationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Organization::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mon association')
            ->setEntityLabelInPlural('Mon association')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Mon association')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier mon association');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            // One record that already exists: nothing to add, nothing to remove, and no
            // list to show. An association must not be able to delete itself.
            // EDIT is not added to the detail page: EasyAdmin puts it there already, and
            // adding it a second time is an InvalidArgumentException, not a no-op.
            ->disable(Action::NEW, Action::DELETE, Action::INDEX)
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, static fn (Action $action): Action => $action->setLabel('Enregistrer'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom de l\'association');

        // Read-only, and not merely discouraged: the slug addresses the public volunteer
        // URLs (/a/{slug}/…), so changing it invalidates every link already handed out. A
        // super-admin can still change it from /platform, deliberately.
        yield TextField::new('slug', 'Raccourci public')
            ->setFormTypeOption('disabled', true)
            ->setHelp('Utilisé dans l\'adresse du formulaire public : /a/{raccourci}/declaration. '
                .'Modifiable seulement par l\'administrateur de la plateforme, car le changer '
                .'invalide les liens déjà communiqués aux bénévoles.');

        yield FormField::addFieldset('Reçus fiscaux')
            ->setHelp('Ces informations figurent sur chaque reçu fiscal (CERFA 2041-RD). '
                .'Sans numéro SIREN ou RNA et sans adresse complète, aucun reçu n\'est émis.');

        yield TextField::new('sirenOrRna', 'Numéro SIREN ou RNA')
            ->setHelp('9 chiffres pour un SIREN, ou W suivi de 9 chiffres pour un numéro RNA.');

        yield TextareaField::new('objet', 'Objet de l\'association')
            ->setHelp('Repris tel quel sur le reçu.');

        yield TextField::new('addressNumber', 'Numéro');
        yield TextField::new('addressStreet', 'Rue');
        yield TextField::new('addressPostcode', 'Code postal');
        yield TextField::new('addressCity', 'Commune');
        yield CountryField::new('addressCountry', 'Pays');

        yield FormField::addFieldset('Signature')
            ->setHelp('Apposée dans le cadre « Date et signature » du reçu fiscal. '
                .'Sans signature enregistrée, le reçu part avec ce cadre vide, à signer à la main.');

        yield Field::new('signature', 'Signature enregistrée')
            ->setTemplatePath('admin/field/organization_signature.html.twig')
            ->onlyOnDetail();

        // Not an ImageField: that one uploads to a directory under public/, which on a
        // container filesystem disappears at the next deployment. The file is stored in
        // the database instead — see App\Entity\OrganizationSignature.
        yield Field::new('signatureUpload', 'Déposer une signature')
            ->setFormType(FileType::class)
            ->setFormTypeOptions(['required' => false])
            ->setHelp('Image PNG ou JPEG, 1 Mo maximum. Laissez vide pour conserver la signature actuelle.')
            ->onlyOnForms();

        // Declared after the upload on purpose: the fields are bound in this order, so if
        // someone both uploads a file and ticks the box, the box wins and nothing is stored.
        yield BooleanField::new('signatureCleared', 'Supprimer la signature actuelle')
            ->renderAsSwitch(false)
            ->onlyOnForms();
    }

    /**
     * There is no list of associations here — one record, reached directly.
     *
     * @param AdminContext<Organization> $context
     */
    public function index(AdminContext $context): Response
    {
        return new RedirectResponse($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($this->tenantContext->getOrganization()->getId()->toRfc4122())
            ->generateUrl());
    }

    /**
     * @param AdminContext<Organization> $context
     */
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->refuseAnotherAssociation($context);

        return parent::detail($context);
    }

    /**
     * @param AdminContext<Organization> $context
     */
    public function edit(AdminContext $context): KeyValueStore|Response
    {
        $this->refuseAnotherAssociation($context);

        return parent::edit($context);
    }

    /**
     * Checked again here, not only in edit(): a POST reaching this with a foreign instance
     * must not be saved, and belt-and-braces is cheap for the one entity in this dashboard
     * that no filter guards.
     */
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        // $entityInstance is narrowed to Organization by the @extends generic.
        if (!$entityInstance->getId()->equals($this->tenantContext->getOrganization()->getId())) {
            throw new NotFoundHttpException('Organization not found.');
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * A 404 rather than a 403: which other associations exist is not this admin's business.
     *
     * @param AdminContext<Organization> $context
     */
    private function refuseAnotherAssociation(AdminContext $context): void
    {
        $organization = $context->getEntity()->getInstance();

        if (!$organization instanceof Organization
            || !$organization->getId()->equals($this->tenantContext->getOrganization()->getId())) {
            throw new NotFoundHttpException('Organization not found.');
        }
    }
}
