<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EventType;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

/**
 * The association's own list of event types — the one section where an admin
 * genuinely creates rows.
 *
 * EventType is TenantAware, so OrganizationFilter scopes every query here and
 * nothing in this class deals with tenancy, except createEntity(): the form has no
 * organization field, so the tenant has to be supplied when the object is built.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<EventType>
 */
#[IsGranted('ROLE_ADMIN')]
final class EventTypeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return EventType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Type d\'événement')
            ->setEntityLabelInPlural('Types d\'événement')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Ces types alimentent le formulaire public. Désactivez-en un pour le '
                .'retirer des nouvelles déclarations sans toucher à l\'historique.',
            );
    }

    /**
     * EventType's constructor requires the tenant, because nothing in the form
     * supplies it and an untenanted type would be invisible to everyone.
     */
    public function createEntity(string $entityFqcn): EventType
    {
        return new EventType($this->tenantContext->getOrganization());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');

        yield BooleanField::new('active', 'Proposé aux bénévoles')
            ->setHelp('Un type désactivé reste lisible sur les déclarations passées.');

        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
    }

    /**
     * The database refuses to delete a type an action still references
     * (ON DELETE RESTRICT), because a filed declaration must not lose the label it
     * was filed under. That surfaces as a driver exception, which would reach the
     * admin as a 500 — so it is turned into something a treasurer can act on.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            parent::deleteEntity($entityManager, $entityInstance);
        } catch (ForeignKeyConstraintViolationException) {
            // $entityInstance is narrowed to EventType by the @extends generic.
            $this->addFlash('danger', sprintf(
                'Le type « %s » est utilisé par des déclarations et ne peut pas être supprimé. '
                .'Décochez « Proposé aux bénévoles » pour le retirer des nouvelles déclarations.',
                $entityInstance->getName(),
            ));
        }
    }
}
