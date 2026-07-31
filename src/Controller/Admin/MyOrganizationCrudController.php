<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organization;
use App\Exception\SignatureImageException;
use App\Organization\SignatureFactory;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\CountryField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    private const string FIELD_SIGNATURE_UPLOAD = 'signatureUpload';
    private const string FIELD_SIGNATURE_CLEARED = 'signatureCleared';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly SignatureFactory $signatures,
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
        // container filesystem disappears at the next deployment. The file is stored in the
        // database instead — see App\Entity\OrganizationSignature.
        //
        // UNMAPPED, both of them. The entity holds no UploadedFile (it is in the session graph)
        // and does no image work; applySignature() below reads these two fields after the form
        // has bound and asks App\Organization\SignatureFactory for the result.
        yield Field::new(self::FIELD_SIGNATURE_UPLOAD, 'Déposer une signature')
            ->setFormType(FileType::class)
            ->setFormTypeOptions(['required' => false, 'mapped' => false])
            ->setHelp('Image PNG ou JPEG, 16 Mo maximum — déposez votre scan tel quel, il sera '
                .'réduit automatiquement à la taille utile sur le reçu. Laissez vide pour '
                .'conserver la signature actuelle.')
            ->onlyOnForms();

        yield Field::new(self::FIELD_SIGNATURE_CLEARED, 'Supprimer la signature actuelle')
            ->setFormType(CheckboxType::class)
            ->setFormTypeOptions(['required' => false, 'mapped' => false])
            ->onlyOnForms();
    }

    /**
     * Reads the unmapped signature fields once the form has bound.
     *
     * POST_SUBMIT and not updateEntity(): the fields are unmapped, so nothing writes them to
     * the entity by itself, and an error added here still marks the form invalid — which
     * updateEntity(), running only on a valid form, would be too late to do.
     *
     * @param AdminContext<Organization> $context
     *
     * @return FormBuilderInterface<Organization>
     */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return parent::createEditFormBuilder($entityDto, $formOptions, $context)
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $organization = $event->getData();
                $form = $event->getForm();

                if ($organization instanceof Organization) {
                    $this->applySignature($form, $organization);
                }
            });
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
     * Applies the two unmapped signature fields once the form has bound.
     *
     * Here rather than in a setter on the entity, for two reasons that both matter: an
     * UploadedFile must never be held on Organization (it is reachable from the security token,
     * which ContextListener serialises into the session on every response), and turning a file
     * into a stored signature needs a service — which a setter cannot reach.
     *
     * A refusal becomes an error on the file field. Throwing would be a 500 over someone
     * picking the wrong file, and this runs while the form is binding.
     *
     * An empty file input means "leave the signature alone", not "remove it" — removal is the
     * checkbox, which is read second so that ticking it wins over an upload in the same submit.
     *
     * @param FormInterface<Organization> $form
     */
    private function applySignature(FormInterface $form, Organization $organization): void
    {
        $upload = $form->get(self::FIELD_SIGNATURE_UPLOAD)->getData();

        if ($upload instanceof UploadedFile) {
            try {
                $organization->setSignature($this->signatures->fromUploadedFile($upload));
            } catch (SignatureImageException $e) {
                $form->get(self::FIELD_SIGNATURE_UPLOAD)->addError(new FormError($e->userMessage));
            }
        }

        if (true === $form->get(self::FIELD_SIGNATURE_CLEARED)->getData()) {
            // orphanRemoval on the association deletes the row on flush.
            $organization->setSignature(null);
        }
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
