<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Declaration\DeclarationDecider;
use App\Declaration\Exception\DeclarationNotDecidableException;
use App\Entity\Declaration;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

/**
 * The declarations of the current association, and where a treasurer rules on
 * them.
 *
 * "Valider tout" / "Refuser tout" cascade to every line and then transition the
 * declaration, through App\Declaration\DeclarationDecider. They are hidden once a
 * single verdict no longer applies — a mixed basket has no whole-declaration
 * outcome, which is the accepted consequence of Declaration having its own state
 * machine (see App\State\DeclarationState).
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Declaration>
 */
#[IsGranted('ROLE_ADMIN')]
final class DeclarationCrudController extends AbstractCrudController
{
    private const string ACTION_VALIDATE_ALL = 'validateAll';
    private const string ACTION_REFUSE_ALL = 'refuseAll';

    public function __construct(
        private readonly DeclarationDecider $decider,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Declaration::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Déclaration')
            ->setEntityLabelInPlural('Déclarations')
            ->setDefaultSort(['submittedAt' => 'DESC'])
            ->setSearchFields(['person.firstName', 'person.lastName', 'person.email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $validateAll = Action::new(self::ACTION_VALIDATE_ALL, 'Valider tout', 'fa fa-check')
            ->linkToCrudAction(self::ACTION_VALIDATE_ALL)
            ->displayIf(fn (Declaration $declaration): bool => $this->decider->canValidateAll($declaration));

        $refuseAll = Action::new(self::ACTION_REFUSE_ALL, 'Refuser tout', 'fa fa-xmark')
            ->linkToCrudAction(self::ACTION_REFUSE_ALL)
            ->displayIf(fn (Declaration $declaration): bool => $this->decider->canRefuseAll($declaration));

        return $actions
            // Declarations arrive from the public form; one typed here by hand
            // would have no volunteer behind it.
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $validateAll)
            ->add(Crud::PAGE_INDEX, $refuseAll)
            ->add(Crud::PAGE_DETAIL, $validateAll)
            ->add(Crud::PAGE_DETAIL, $refuseAll);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('person', 'Bénévole');
        yield DateTimeField::new('submittedAt', 'Déposée le');

        yield ChoiceField::new('state', 'État')
            ->setChoices(['Soumise' => 'submitted', 'Validée' => 'validated', 'Refusée' => 'refused'])
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => $declaration->getState()->label())
            ->renderAsBadges([
                'submitted' => 'warning',
                'validated' => 'success',
                'refused' => 'danger',
            ]);

        yield IntegerField::new('actions.count', 'Actions')
            ->onlyOnIndex();

        yield TextField::new('totalWorkHours', 'Heures déclarées')
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => sprintf('%s h', $declaration->getTotalWorkHours()));

        yield IntegerField::new('totalDistanceKm', 'Kilomètres déclarés')
            ->setHelp('Distance d\'un trajet × nombre de trajets, pour chaque action.');

        yield AssociationField::new('actions', 'Actions déclarées')
            ->onlyOnDetail();

        yield BooleanField::new('accuracyAttested', 'Exactitude attestée')
            ->renderAsSwitch(false)
            ->onlyOnDetail();

        yield BooleanField::new('expensesWaived', 'Renonciation aux frais')
            ->renderAsSwitch(false)
            ->setHelp('Obligatoire : c\'est cette renonciation qui ouvre droit au reçu fiscal.')
            ->onlyOnDetail();
    }

    /**
     * #[AdminRoute] is required, not optional: EasyAdmin 5 creates no route for a
     * custom CRUD action without it, and linkToCrudAction() would point nowhere.
     *
     * @param AdminContext<Declaration> $context
     */
    #[AdminRoute(path: '/{entityId}/validate-all', name: 'validate_all', options: ['methods' => ['GET']])]
    public function validateAll(AdminContext $context): Response
    {
        return $this->decide($context, decide: true);
    }

    /**
     * @param AdminContext<Declaration> $context
     */
    #[AdminRoute(path: '/{entityId}/refuse-all', name: 'refuse_all', options: ['methods' => ['GET']])]
    public function refuseAll(AdminContext $context): Response
    {
        return $this->decide($context, decide: false);
    }

    /**
     * @param AdminContext<Declaration> $context
     */
    private function decide(AdminContext $context, bool $decide): Response
    {
        // Declaration IS TenantAware, so OrganizationFilter already makes another
        // tenant's row unloadable — the instance simply comes back null. That is a
        // 404, not an assertion failure: a custom CRUD action gets a null instance
        // rather than a refused request.
        $declaration = $context->getEntity()->getInstance();

        if (!$declaration instanceof Declaration) {
            throw new NotFoundHttpException('Declaration not found.');
        }

        try {
            if ($decide) {
                $this->decider->validateAll($declaration);
            } else {
                $this->decider->refuseAll($declaration);
            }

            $this->addFlash('success', sprintf(
                'Déclaration de %s : %s.',
                $declaration->getPerson()->getFullName(),
                $declaration->getState()->label(),
            ));
        } catch (DeclarationNotDecidableException $e) {
            // A legitimate runtime state — typically a mixed basket — so it is
            // reported to the treasurer rather than left to the error handler. The
            // French message, not the developer-facing one.
            $this->addFlash('danger', $e->getUserMessage());
        }

        return new RedirectResponse(
            $this->adminUrlGenerator->setAction(Action::DETAIL)
                ->setEntityId($declaration->getId()->toRfc4122())
                ->generateUrl(),
        );
    }
}
