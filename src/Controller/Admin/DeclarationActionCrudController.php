<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DeclarationAction;
use App\Security\Voter\DeclarationActionVoter;
use App\State\DeclarationActionState;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Finite\StateMachine;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

/**
 * Individual declared actions, so a treasurer can rule line by line.
 *
 * ⚠ TENANCY IS MANUAL HERE. DeclarationAction does not implement TenantAware, so
 * OrganizationFilter does NOT scope it and this controller has to do the work
 * itself, in two places that are easy to forget:
 *
 *   1. createIndexQueryBuilder() joins the declaration and constrains it to the
 *      current tenant. This covers the index and the autocomplete endpoint.
 *   2. Crud::setEntityPermission() runs DeclarationActionVoter on single-record
 *      pages — detail, edit, delete — which fetch by id and never touch the query
 *      builder above.
 *
 * Both are covered by tests/Controller/Admin/DeclarationActionIsolationTest.
 * If DeclarationAction ever gains an organization FK through TenantAwareTrait,
 * delete all of this: the filter would cover it automatically.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<DeclarationAction>
 */
#[IsGranted('ROLE_ADMIN')]
final class DeclarationActionCrudController extends AbstractCrudController
{
    private const string ACTION_VALIDATE = 'validateAction';
    private const string ACTION_REFUSE = 'refuseAction';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StateMachine $stateMachine,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return DeclarationAction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Action déclarée')
            ->setEntityLabelInPlural('Actions déclarées')
            ->setDefaultSort(['date' => 'DESC'])
            ->setSearchFields(['title', 'description'])
            // Guards detail/edit/delete, which do not go through
            // createIndexQueryBuilder(). See the class docblock.
            ->setEntityPermission(DeclarationActionVoter::VIEW);
    }

    /**
     * The tenant scope for every listing on this entity.
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->innerJoin('entity.declaration', 'scoped_declaration')
            ->andWhere('scoped_declaration.organization = :tenant')
            ->setParameter('tenant', $this->tenantContext->getOrganization()->getId(), 'uuid');
    }

    public function configureActions(Actions $actions): Actions
    {
        $validate = Action::new(self::ACTION_VALIDATE, 'Valider', 'fa fa-check')
            ->linkToCrudAction(self::ACTION_VALIDATE)
            ->displayIf(fn (DeclarationAction $action): bool => $this->stateMachine->can($action, DeclarationActionState::TRANSITION_VALIDATE));

        $refuse = Action::new(self::ACTION_REFUSE, 'Refuser', 'fa fa-xmark')
            ->linkToCrudAction(self::ACTION_REFUSE)
            ->displayIf(fn (DeclarationAction $action): bool => $this->stateMachine->can($action, DeclarationActionState::TRANSITION_REFUSE));

        return $actions
            // Actions come from the public form, like the declarations holding them.
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $validate)
            ->add(Crud::PAGE_INDEX, $refuse)
            ->add(Crud::PAGE_DETAIL, $validate)
            ->add(Crud::PAGE_DETAIL, $refuse);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('date', 'Date');
        yield TextField::new('title', 'Intitulé');

        yield AssociationField::new('eventType', 'Type');

        yield AssociationField::new('declaration', 'Déclaration');

        yield ChoiceField::new('state', 'État')
            ->formatValue(static fn (mixed $value, DeclarationAction $action): string => $action->getState()->label())
            // Built from the enum, as in DeclarationCrudController: this map used to
            // be written out by hand, so adding a state left the new one with no
            // badge and nothing to say so.
            ->renderAsBadges(array_combine(
                array_map(static fn (DeclarationActionState $state): string => $state->value, DeclarationActionState::cases()),
                array_map(static fn (DeclarationActionState $state): string => $state->badgeStyle(), DeclarationActionState::cases()),
            ));

        yield TextField::new('workHours', 'Heures')
            ->formatValue(static fn (mixed $value, DeclarationAction $action): string => sprintf('%s h', $action->getWorkHours()));

        yield IntegerField::new('consecutiveDays', 'Jours consécutifs')->onlyOnDetail();
        yield IntegerField::new('journeys', 'Trajets')->onlyOnDetail();
        yield IntegerField::new('distanceKm', 'Distance d\'un trajet (km)')->onlyOnDetail();

        yield IntegerField::new('totalDistanceKm', 'Kilomètres au total')
            ->setHelp('Distance d\'un trajet × nombre de trajets.');

        yield BooleanField::new('ownVehicle', 'Véhicule personnel')
            ->renderAsSwitch(false)
            ->onlyOnDetail();

        yield ChoiceField::new('fiscalPower', 'Puissance fiscale')
            ->formatValue(static fn (mixed $value, DeclarationAction $action): string => $action->getFiscalPower()?->label() ?? '—')
            ->onlyOnDetail();

        yield TextField::new('description', 'Description')->onlyOnDetail();
    }

    /**
     * #[AdminRoute] is required for a custom CRUD action in EasyAdmin 5 — see
     * App\Controller\Admin\DeclarationCrudController.
     *
     * @param AdminContext<DeclarationAction> $context
     */
    #[AdminRoute(path: '/{entityId}/validate', name: 'validate', options: ['methods' => ['GET']])]
    public function validateAction(AdminContext $context): Response
    {
        return $this->applyTransition($context, DeclarationActionState::TRANSITION_VALIDATE);
    }

    /**
     * @param AdminContext<DeclarationAction> $context
     */
    #[AdminRoute(path: '/{entityId}/refuse', name: 'refuse', options: ['methods' => ['GET']])]
    public function refuseAction(AdminContext $context): Response
    {
        return $this->applyTransition($context, DeclarationActionState::TRANSITION_REFUSE);
    }

    /**
     * @param AdminContext<DeclarationAction> $context
     */
    private function applyTransition(AdminContext $context, string $transition): Response
    {
        // Crud::setEntityPermission() does NOT cover custom CRUD actions: EasyAdmin
        // marks the entity inaccessible and hands over a null instance rather than
        // refusing the request, so a custom action has to check for itself. Without
        // this an admin could decide another tenant's line.
        $entity = $context->getEntity();

        if (!$entity->isAccessible()) {
            throw new AccessDeniedException('This declaration action belongs to another organization.');
        }

        $action = $entity->getInstance();

        if (!$action instanceof DeclarationAction) {
            throw new NotFoundHttpException('Declaration action not found.');
        }

        // Belt and braces: assert the tenant explicitly, so the guarantee does not
        // rest solely on EasyAdmin having applied the permission upstream.
        $this->denyAccessUnlessGranted(DeclarationActionVoter::VIEW, $action);

        if ($this->stateMachine->can($action, $transition)) {
            $this->stateMachine->apply($action, $transition);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('« %s » : %s.', $action->getTitle(), $action->getState()->label()));
        } else {
            $this->addFlash('warning', sprintf('« %s » a déjà été traitée.', $action->getTitle()));
        }

        return new RedirectResponse(
            $this->adminUrlGenerator->setAction(Action::INDEX)->generateUrl(),
        );
    }
}
