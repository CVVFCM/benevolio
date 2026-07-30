<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Declaration\DeclarationDecider;
use App\Declaration\Exception\DeclarationNotDecidableException;
use App\Entity\Declaration;
use App\State\DeclarationState;
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

use function abs;
use function intdiv;
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

        // The volunteer's contact details, on detail only. A treasurer ruling on a
        // declaration should not have to open the Bénévole page to see who it is —
        // and the address is what a CERFA receipt is eventually addressed to.
        // Dot notation into the association, as with actions.count below.
        yield TextField::new('person.email', 'Adresse électronique')
            ->onlyOnDetail();

        yield TextField::new('person.address', 'Adresse postale')
            // Address is an embeddable, so it needs telling how to read: reuse its
            // own readable line rather than reassembling the parts here.
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => (string) $declaration->getPerson()->getAddress())
            ->onlyOnDetail();

        yield DateTimeField::new('submittedAt', 'Déposée le');

        yield ChoiceField::new('state', 'État')
            // Built from the enum so a new state cannot be forgotten here.
            ->setChoices(array_combine(
                array_map(static fn (DeclarationState $state): string => $state->label(), DeclarationState::cases()),
                array_map(static fn (DeclarationState $state): string => $state->value, DeclarationState::cases()),
            ))
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => $declaration->getState()->label())
            ->renderAsBadges(array_combine(
                array_map(static fn (DeclarationState $state): string => $state->value, DeclarationState::cases()),
                array_map(static fn (DeclarationState $state): string => $state->badgeStyle(), DeclarationState::cases()),
            ));

        yield IntegerField::new('actions.count', 'Actions')
            ->onlyOnIndex();

        yield TextField::new('totalWorkHours', 'Heures déclarées')
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => sprintf('%s h', $declaration->getTotalWorkHours()));

        yield IntegerField::new('totalDistanceKm', 'Kilomètres déclarés')
            ->setHelp('Distance d\'un trajet × nombre de trajets, pour chaque action.');

        // A to-many association renders as a list of stringified links, which for
        // the page a treasurer rules from says almost nothing. The template lays the
        // lines out as a table so the figures being ruled on are actually visible.
        yield AssociationField::new('actions', 'Actions déclarées')
            ->setTemplatePath('admin/field/declaration_actions.html.twig')
            ->onlyOnDetail();

        // The receipt, or why there is not one. Both matter to a treasurer: "no receipt"
        // on its own reads as a fault rather than as paperwork to finish.
        yield TextField::new('receipt', 'Reçu fiscal')
            ->formatValue(static function (mixed $value, Declaration $declaration): string {
                $receipt = $declaration->getReceipt();

                if (null !== $receipt) {
                    return sprintf(
                        'N° %s — %d,%02d € — émis le %s',
                        $receipt->getNumber(),
                        intdiv($receipt->getAmountCents(), 100),
                        abs($receipt->getAmountCents() % 100),
                        $receipt->getIssuedAt()->format('d/m/Y'),
                    );
                }

                return $declaration->getReceiptWithheldReason() ?? 'Aucun reçu émis.';
            })
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

            // Say what happened to the receipt, at the moment of the decision.
            //
            // Issuance runs synchronously inside validateAll() (the message is routed to
            // the `sync` transport), so by now the declaration carries either a receipt
            // or the reason it has none. Leaving that on the detail page only meant a
            // treasurer validated a declaration, saw nothing arrive, and had no way to
            // tell whether the application had refused on purpose or simply broken.
            $this->flashReceiptOutcome($declaration);
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

    /**
     * The receipt outcome as its own flash, so a refusal is never silent.
     *
     * A refusal is `info`, not `warning`: most of them are ordinary — donated hours are
     * simply not receiptable — and colouring them as problems would train a treasurer to
     * ignore the one that is.
     */
    private function flashReceiptOutcome(Declaration $declaration): void
    {
        $receipt = $declaration->getReceipt();

        if (null !== $receipt) {
            $this->addFlash('success', sprintf(
                'Reçu fiscal n° %s (%d,%02d €) envoyé à %s.',
                $receipt->getNumber(),
                intdiv($receipt->getAmountCents(), 100),
                abs($receipt->getAmountCents() % 100),
                $declaration->getPerson()->getEmail()->value,
            ));

            return;
        }

        $reason = $declaration->getReceiptWithheldReason();

        if (null !== $reason) {
            $this->addFlash('info', sprintf('Aucun reçu fiscal. %s', $reason));
        }
    }
}
