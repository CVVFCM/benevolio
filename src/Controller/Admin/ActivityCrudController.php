<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Activity;
use App\Enum\ActivityType;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

/**
 * The association's events and actions — what a volunteer picks on a declaration line
 * instead of typing the occasion (#44).
 *
 * Activity is TenantAware, so OrganizationFilter scopes every query here; only
 * createEntity() deals with tenancy, as in TaskCrudController.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Activity>
 */
#[IsGranted('ROLE_ADMIN')]
final class ActivityCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Activity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Événement ou action')
            ->setEntityLabelInPlural('Événements et actions')
            ->setDefaultSort(['beginsOn' => 'DESC', 'name' => 'ASC'])
            ->setSearchFields(['name'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Les bénévoles choisissent dans cette liste ce pour quoi ils ont donné leur temps ; '
                .'« Autre » leur laisse un intitulé libre. Un événement dont la date de début est à '
                .'venir n\'est pas encore proposé. Désactivez-en un pour le retirer des nouvelles '
                .'déclarations sans toucher à l\'historique.',
            );
    }

    /**
     * Activity's constructor requires the tenant: nothing in the form supplies it.
     */
    public function createEntity(string $entityFqcn): Activity
    {
        return new Activity($this->tenantContext->getOrganization());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('type', 'Type')->setChoices(ActivityType::choices()))
            ->add(BooleanFilter::new('active', 'Proposé aux bénévoles'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')
            ->setHelp('Renommer un événement le renomme aussi sur les déclarations déjà déposées.');

        yield ChoiceField::new('type', 'Type')
            ->setChoices(ActivityType::choices())
            ->renderExpanded();

        yield DateField::new('beginsOn', 'Début')
            ->setRequired(false)
            ->setHelp('Facultatif. Un seul jour : indiquez uniquement le début.');

        yield DateField::new('endsOn', 'Fin')
            ->setRequired(false);

        yield BooleanField::new('active', 'Proposé aux bénévoles')
            ->setHelp('Un événement désactivé reste lisible sur les déclarations passées.');

        yield DateTimeField::new('createdAt', 'Créé le')
            ->hideOnForm();
    }

    /**
     * The database refuses to delete an activity a line still references — see
     * TaskCrudController::deleteEntity(), whose reasoning this repeats.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            parent::deleteEntity($entityManager, $entityInstance);
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('danger', sprintf(
                '« %s » est utilisé par des déclarations et ne peut pas être supprimé. '
                .'Décochez « Proposé aux bénévoles » pour le retirer des nouvelles déclarations.',
                $entityInstance->getName(),
            ));
        }
    }
}
