<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Task;
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
 * The association's own list of tasks a volunteer can perform — the one section
 * where an admin genuinely creates rows.
 *
 * Task is TenantAware, so OrganizationFilter scopes every query here and
 * nothing in this class deals with tenancy, except createEntity(): the form has no
 * organization field, so the tenant has to be supplied when the object is built.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Task>
 */
#[IsGranted('ROLE_ADMIN')]
final class TaskCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Task::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tâche effectuée')
            ->setEntityLabelInPlural('Tâches effectuées')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Ces tâches alimentent le formulaire public. Désactivez-en une pour la '
                .'retirer des nouvelles déclarations sans toucher à l\'historique.',
            );
    }

    /**
     * Task's constructor requires the tenant, because nothing in the form
     * supplies it and an untenanted task would be invisible to everyone.
     */
    public function createEntity(string $entityFqcn): Task
    {
        return new Task($this->tenantContext->getOrganization());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');

        yield BooleanField::new('active', 'Proposé aux bénévoles')
            ->setHelp('Une tâche désactivée reste lisible sur les déclarations passées.');

        // No rate here. A task's hourly rate is per financial year, so it lives on
        // App\Entity\FiscalYear as an override — see FiscalYearCrudController.

        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
    }

    /**
     * The database refuses to delete a type an action still references, because a
     * filed declaration must not lose the label it was filed under. Left alone that
     * reaches the admin as EasyAdmin's own 409 page, whose message is written for a
     * developer ("disable the delete action or configure cascade") and in English —
     * so it is turned into something a treasurer can act on instead.
     *
     * This catch only fires because the constraint is NO ACTION and not RESTRICT;
     * see App\Entity\DeclarationAction for why that distinction decides whether the
     * exception is catchable at all.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            parent::deleteEntity($entityManager, $entityInstance);
        } catch (ForeignKeyConstraintViolationException) {
            // $entityInstance is narrowed to Task by the @extends generic.
            $this->addFlash('danger', sprintf(
                'Le type « %s » est utilisé par des déclarations et ne peut pas être supprimé. '
                .'Décochez « Proposé aux bénévoles » pour le retirer des nouvelles déclarations.',
                $entityInstance->getName(),
            ));
        }
    }
}
