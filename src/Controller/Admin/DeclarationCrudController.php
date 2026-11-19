<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Declaration\DeclarationConfirmer;
use App\Declaration\DeclarationDecider;
use App\Declaration\Exception\DeclarationNotConfirmableException;
use App\Declaration\Exception\DeclarationNotDecidableException;
use App\Entity\Declaration;
use App\Entity\User;
use App\Security\RequiresActionToken;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use LogicException;
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
 * "Confirmer à la place du bénévole" is the escape hatch for a confirmation mail
 * that never arrived. Detail page only, behind a modal that says it is not a
 * normal operation, and a POST with a token like the verdicts: a state change that
 * skips the double opt-in must not be reachable from a prefetched or pasted URL. See
 * App\Security\RequiresActionToken.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Declaration>
 */
#[IsGranted('ROLE_ADMIN')]
final class DeclarationCrudController extends AbstractCrudController
{
    /** Suffixed with the declaration id, so a token minted for one cannot confirm another. */
    private const string ACTION_VALIDATE_ALL = 'validateAll';
    private const string ACTION_REFUSE_ALL = 'refuseAll';
    private const string ACTION_CONFIRM_MANUALLY = 'confirmManually';

    public function __construct(
        private readonly DeclarationDecider $decider,
        private readonly DeclarationConfirmer $confirmer,
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
            ->setSearchFields(['person.firstName', 'person.lastName', 'person.email'])
            // Inlined: the dropdown renders its own <form> and ignores an action's
            // template, so the verdict buttons would post without their CSRF token.
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $validateAll = Action::new(self::ACTION_VALIDATE_ALL, 'Valider tout', 'fa fa-check')
            ->linkToCrudAction(self::ACTION_VALIDATE_ALL)
            // POST with a token, never a link: see post_with_token.html.twig.
            ->renderAsForm()
            ->setTemplatePath('admin/action/post_with_token.html.twig')
            ->displayIf(fn (Declaration $declaration): bool => $this->decider->canValidateAll($declaration));

        $refuseAll = Action::new(self::ACTION_REFUSE_ALL, 'Refuser tout', 'fa fa-xmark')
            ->linkToCrudAction(self::ACTION_REFUSE_ALL)
            ->renderAsForm()
            ->setTemplatePath('admin/action/post_with_token.html.twig')
            ->asDangerAction()
            ->askConfirmation('Refuser toute cette déclaration ?', 'Refuser tout')
            ->displayIf(fn (Declaration $declaration): bool => $this->decider->canRefuseAll($declaration));

        $confirmManually = Action::new(self::ACTION_CONFIRM_MANUALLY, 'Confirmer à la place du bénévole', 'fa fa-user-check')
            ->linkToCrudAction(self::ACTION_CONFIRM_MANUALLY)
            ->renderAsForm()
            ->setTemplatePath('admin/action/post_with_token.html.twig')
            ->asWarningAction()
            ->askConfirmation('Confirmer cette déclaration à la place du bénévole ?', 'Confirmer quand même')
            // EasyAdmin's modal script shows this under the title for any action,
            // although only DELETE sets it natively.
            ->setHtmlAttributes([
                'data-action-confirmation-content' => 'Ce n\'est pas une opération normale. D\'ordinaire, le bénévole '
                    .'confirme lui-même en cliquant sur le lien reçu par courriel : ce clic prouve que son adresse '
                    .'fonctionne (c\'est à elle que sera envoyé le reçu fiscal) et qu\'il a bien voulu envoyer '
                    .'cette déclaration. Ne le faites que si ce courriel ne lui est jamais parvenu et que vous avez '
                    .'vérifié avec lui. Votre compte sera enregistré sur la déclaration comme l\'ayant confirmée.',
            ])
            ->displayIf(fn (Declaration $declaration): bool => $this->confirmer->canConfirmManually($declaration));

        return $actions
            // Declarations arrive from the public form; one typed here by hand
            // would have no volunteer behind it.
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $validateAll)
            ->add(Crud::PAGE_INDEX, $refuseAll)
            ->add(Crud::PAGE_DETAIL, $validateAll)
            ->add(Crud::PAGE_DETAIL, $refuseAll)
            ->add(Crud::PAGE_DETAIL, $confirmManually);
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
            //
            // ESCAPED BY HAND, and it has to be. EasyAdmin wraps whatever string a
            // formatValue() callback returns in a Twig Markup and prints it raw — the
            // callback is trusted to return HTML. The address was typed on the public
            // form by anyone at all, so unescaped it ran as script in the treasurer's
            // session. Any formatValue() returning text a person typed must do this.
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => htmlspecialchars((string) $declaration->getPerson()->getAddress()))
            ->onlyOnDetail();

        // What THIS declaration states, which until it is confirmed may differ from
        // the volunteer's record above — a submission no longer rewrites the record.
        // Its template says so when it does, because confirming by hand applies it.
        yield Field::new('submittedFullName', 'Coordonnées saisies')
            ->setTemplatePath('admin/field/declaration_submitted_identity.html.twig')
            ->setSortable(false)
            ->onlyOnDetail();

        yield DateTimeField::new('submittedAt', 'Déposée le');

        yield ChoiceField::new('state', 'État')
            ->setChoices(DeclarationState::choices())
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => $declaration->getState()->label())
            ->renderAsBadges(DeclarationState::badgeStyles());

        // Who confirmed matters as much as when: a manual confirmation proves less
        // than the volunteer's own click, and the reader has to be able to tell.
        // One line rather than a date field beside it, which would read « Aucun(e) »
        // on every declaration still waiting.
        yield TextField::new('confirmedBy', 'Confirmation')
            ->formatValue(static function (mixed $value, Declaration $declaration): string {
                $confirmedAt = $declaration->getConfirmedAt();

                if (null === $confirmedAt) {
                    return 'En attente du bénévole';
                }

                $by = $declaration->getConfirmedBy();

                return null === $by
                    ? sprintf('Le %s, par le bénévole, via le lien reçu par courriel', $confirmedAt->format('d/m/Y à H:i'))
                    : sprintf(
                        'Le %s, par %s, à la place du bénévole (confirmation manuelle)',
                        $confirmedAt->format('d/m/Y à H:i'),
                        $by->getEmail(),
                    );
            })
            ->onlyOnDetail();

        yield IntegerField::new('actions.count', 'Actions')
            ->onlyOnIndex();

        yield TextField::new('totalHours', 'Heures déclarées')
            ->formatValue(static fn (mixed $value, Declaration $declaration): string => $declaration->getTotalHours()->format());

        yield IntegerField::new('totalDistanceKm', 'Kilomètres déclarés')
            ->setHelp('Distance d\'un trajet × nombre de trajets, pour chaque action.');

        // A to-many association renders as a list of stringified links, which for
        // the page a treasurer rules from says almost nothing. The template lays the
        // lines out as a table so the figures being ruled on are actually visible.
        yield AssociationField::new('actions', 'Actions déclarées')
            ->setTemplatePath('admin/field/declaration_actions.html.twig')
            ->onlyOnDetail();

        // No receipt field. A reçu fiscal covers a volunteer's whole civil year, so it
        // belongs to no single declaration — see the « Reçus fiscaux » page.

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
    #[RequiresActionToken]
    #[AdminRoute(path: '/{entityId}/validate-all', name: 'validate_all', options: ['methods' => ['POST']])]
    public function validateAll(AdminContext $context): Response
    {
        return $this->decide($context, decide: true);
    }

    /**
     * @param AdminContext<Declaration> $context
     */
    #[RequiresActionToken]
    #[AdminRoute(path: '/{entityId}/refuse-all', name: 'refuse_all', options: ['methods' => ['POST']])]
    public function refuseAll(AdminContext $context): Response
    {
        return $this->decide($context, decide: false);
    }

    /**
     * @param AdminContext<Declaration> $context
     */
    #[RequiresActionToken]
    #[AdminRoute(path: '/{entityId}/confirm-manually', name: 'confirm_manually', options: ['methods' => ['POST']])]
    public function confirmManually(AdminContext $context): Response
    {
        // See decide(): another tenant's declaration comes back null, hence a 404.
        $declaration = $context->getEntity()->getInstance();

        if (!$declaration instanceof Declaration) {
            throw new NotFoundHttpException('Declaration not found.');
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            // #[IsGranted('ROLE_ADMIN')] already guarantees an App\Entity\User.
            throw new LogicException('A back-office request without an App\Entity\User.');
        }

        try {
            $this->confirmer->confirmManually($declaration, $user);

            $this->addFlash('warning', sprintf(
                'Déclaration de %s confirmée manuellement, à la place du bénévole. Elle peut maintenant être traitée.',
                $declaration->getPerson()->getFullName(),
            ));
        } catch (DeclarationNotConfirmableException $e) {
            // The volunteer clicked in the meantime, or a second admin got there first.
            $this->addFlash('danger', $e->getUserMessage());
        }

        return $this->redirectToDetail($declaration);
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

            // Validating no longer issues anything. Receipts are generated a civil year at
            // a time from « Reçus fiscaux », so there is no receipt outcome to report here
            // — a validated declaration simply becomes part of that year's total.
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

        return $this->redirectToDetail($declaration);
    }

    private function redirectToDetail(Declaration $declaration): RedirectResponse
    {
        return new RedirectResponse(
            $this->adminUrlGenerator->setAction(Action::DETAIL)
                ->setEntityId($declaration->getId()->toRfc4122())
                ->generateUrl(),
        );
    }
}
