<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Accounting\ActivitySummary;
use App\Accounting\ActivitySummaryBuilder;
use App\Entity\Activity;
use App\Enum\ActivityType;
use App\Tenant\TenantContext;
use Closure;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
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
    private const string DEFAULTS_HELP = 'Facultatif. Pré-rempli sur la déclaration quand un bénévole choisit cet événement.';

    private const string SUMMARIES_ATTRIBUTE = '_activity_summaries';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ActivitySummaryBuilder $summaryBuilder,
        private readonly RequestStack $requestStack,
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

        // What the declaration form pre-fills when a volunteer chooses this one (#46).
        // Not in the list: it is a setting, and the list has figures to show instead.
        yield IntegerField::new('defaultDays', 'Nombre de jours')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp(self::DEFAULTS_HELP.' Le nombre de trajets est pré-rempli à deux par jour.');

        yield NumberField::new('defaultWorkHours', 'Nombre d\'heures')
            ->setRequired(false)
            ->hideOnIndex()
            ->setNumDecimals(2)
            ->setStoredAsString()
            ->setHelp(self::DEFAULTS_HELP.' Total sur l\'ensemble des jours.');

        yield BooleanField::new('active', 'Proposé aux bénévoles')
            ->setHelp('Un événement désactivé reste lisible sur les déclarations passées.');

        yield from $this->summaryFields();

        yield DateTimeField::new('createdAt', 'Créé le')
            ->onlyOnDetail();
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

    /**
     * What the validated lines given for an activity add up to (#47): the two amounts
     * and the volunteers in the list, everything on the detail page.
     *
     * None of these is a property of Activity, so each names a template — EasyAdmin
     * prints « Inaccessible » otherwise — and none is sortable: nothing is stored.
     * formatValue() returns figures only, never text a person typed.
     *
     * @return iterable<TextField>
     */
    private function summaryFields(): iterable
    {
        /** @var Closure(string, string, Closure(ActivitySummary): string): TextField $column */
        $column = fn (string $name, string $label, Closure $figure): TextField => TextField::new($name, $label)
            ->setVirtual(true)
            ->setSortable(false)
            ->onlyOnIndex()
            ->setTemplatePath('admin/field/computed.html.twig')
            ->formatValue(fn (mixed $value, Activity $activity): string => $figure($this->summaryOf($activity)));

        yield $column('summaryHoursAmount', 'Bénévolat valorisé', static fn (ActivitySummary $summary): string => $summary->hoursAmount->format());
        yield $column('summaryMileageAmount', 'Abandon de frais', static fn (ActivitySummary $summary): string => $summary->mileageAmount->format());
        yield $column('summaryVolunteers', 'Bénévoles', static fn (ActivitySummary $summary): string => (string) $summary->volunteers);

        yield TextField::new('summary', 'Bilan')
            ->setVirtual(true)
            ->onlyOnDetail()
            ->setTemplatePath('admin/field/activity_summary.html.twig')
            ->formatValue(fn (mixed $value, Activity $activity): ActivitySummary => $this->summaryOf($activity));
    }

    /**
     * Built once per request for the whole association — one query, whatever the number
     * of rows in the list.
     *
     * Kept on the REQUEST, not on this object: under FrankenPHP's worker mode the
     * controller outlives the request, and a property would serve one association's
     * figures to the next — or yesterday's to today.
     */
    private function summaryOf(Activity $activity): ActivitySummary
    {
        $request = $this->requestStack->getMainRequest();
        $summaries = $request?->attributes->get(self::SUMMARIES_ATTRIBUTE);

        if (!is_array($summaries)) {
            $summaries = $this->summaryBuilder->forOrganization($this->tenantContext->getOrganization());
            $request?->attributes->set(self::SUMMARIES_ATTRIBUTE, $summaries);
        }

        $summary = $summaries[$activity->getId()->toRfc4122()] ?? null;

        return $summary instanceof ActivitySummary ? $summary : ActivitySummary::empty();
    }
}
