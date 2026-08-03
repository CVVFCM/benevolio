<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Accounting\LedgerBuilder;
use App\Entity\FiscalYear;
use App\Form\FiscalYearMileageRateType;
use App\Form\FiscalYearTaskRateType;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

use function sprintf;

/**
 * The association's exercices comptables, and the rates that hold for each.
 *
 * FiscalYear is TenantAware, so OrganizationFilter scopes every query here — except
 * createEntity(), where the tenant has to be supplied because the form has no
 * organization field. Same shape as App\Controller\Admin\TaskCrudController.
 *
 * The interesting pages are not the form. `ledger` is the écriture the association books —
 * one centralising entry per family, dated on the exercice's close — and `ledgerDetail` is
 * the per-volunteer breakdown that justifies an individual reçu fiscal. They were one page
 * until the summary turned out to be what a treasurer actually needs.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<FiscalYear>
 */
#[IsGranted('ROLE_ADMIN')]
final class FiscalYearCrudController extends AbstractCrudController
{
    private const string ACTION_LEDGER = 'ledger';
    private const string ACTION_LEDGER_DETAIL = 'ledgerDetail';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LedgerBuilder $ledgerBuilder,
        private readonly Environment $twig,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return FiscalYear::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Exercice comptable')
            ->setEntityLabelInPlural('Exercices comptables')
            // Most recent first: it is the one being worked on.
            ->setDefaultSort(['beginsOn' => 'DESC'])
            ->setSearchFields(['name'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Les taux de valorisation appartiennent à l\'exercice : les modifier ici '
                .'ne touche pas aux exercices déjà clos.',
            )
            // Editing a rate is allowed on any exercice, closed or not — correcting a mistyped
            // rate is the common case and must stay possible. What it changes is worth saying
            // out loud, because two of the three consequences are invisible from this page.
            ->setHelp(
                Crud::PAGE_EDIT,
                'Modifier un taux recalcule les écritures comptables et les montants affichés '
                .'pour cet exercice. Les reçus fiscaux déjà émis ne changent pas : leur montant '
                .'est figé au moment de l\'émission. Si un taux était faux, régénérez l\'année '
                .'concernée depuis « Reçus fiscaux ».',
            );
    }

    /**
     * The constructor needs the tenant, and pre-fills the calendar year — which is
     * what most associations use, so the form opens on a valid exercice rather than
     * on empty date fields.
     */
    public function createEntity(string $entityFqcn): FiscalYear
    {
        return new FiscalYear($this->tenantContext->getOrganization());
    }

    public function configureActions(Actions $actions): Actions
    {
        $ledger = Action::new(self::ACTION_LEDGER, 'Écritures comptables', 'fa fa-book')
            ->linkToCrudAction(self::ACTION_LEDGER);

        $ledgerDetail = Action::new(self::ACTION_LEDGER_DETAIL, 'Détail par bénévole', 'fa fa-users')
            ->linkToCrudAction(self::ACTION_LEDGER_DETAIL);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $ledger)
            ->add(Crud::PAGE_DETAIL, $ledger)
            ->add(Crud::PAGE_DETAIL, $ledgerDetail);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')
            ->setHelp('« 2026 », ou « 2025-2026 » si l\'exercice ne suit pas l\'année civile.');

        yield DateField::new('beginsOn', 'Début');
        yield DateField::new('endsOn', 'Fin');

        // Cents, which is what MoneyField stores natively.
        yield MoneyField::new('defaultHourlyRateCents', 'Taux horaire par défaut')
            ->setCurrency('EUR')
            ->setNumDecimals(2)
            ->setHelp('Valorisation d\'une heure de bénévolat, pour les tâches sans taux propre.');

        // NOT a MoneyField: the value is in millièmes d'euro per kilometre, and
        // MoneyField would read it as cents — 529 would show as 5,29 € instead of
        // 0,529 €/km.
        //
        // And IntegerField, not TextField. A TextField bound to an int column throws
        // ("can't be converted into a string") because TextConfigurator runs before the
        // formatValue callback — the same trap already documented in
        // App\Controller\Admin\TaskCrudController, walked into again here.
        yield IntegerField::new('defaultMilliEurosPerKm', 'Barème kilométrique par défaut')
            ->formatValue(static fn (mixed $value, FiscalYear $year): string => self::formatMilliEuros($year->getDefaultMilliEurosPerKm()))
            ->hideOnForm();

        yield IntegerField::new('defaultMilliEurosPerKm', 'Barème kilométrique par défaut (millièmes d\'euro / km)')
            ->onlyOnForms()
            ->setHelp(
                'En millièmes d\'euro par kilomètre : saisissez 529 pour 0,529 €/km. '
                .'Barème automobile 2025 (arrêté du 27 mars 2023) : 3 CV et moins 529, '
                .'4 CV 606, 5 CV 636, 6 CV 665, 7 CV et plus 697.',
            );

        // Editable, and this is the ONLY way either kind of rate can be created: there is no
        // CRUD for them and there should not be — a rate has no meaning apart from the exercice
        // it belongs to, so it is edited where it lives.
        //
        // The exercice has to be handed to the entry types because both rate entities require it
        // in their constructor; see their `empty_data`. On the "new exercice" form the instance
        // is a fresh FiscalYear that has not been persisted, which is fine: the rows are
        // cascade-persisted with it.
        yield CollectionField::new('mileageRates', 'Barème par puissance fiscale')
            ->allowAdd()
            ->allowDelete()
            ->setEntryType(FiscalYearMileageRateType::class)
            ->setFormTypeOption('entry_options', ['fiscal_year' => $this->fiscalYearOfForm()])
            ->setHelp('Facultatif. Sans ligne ici, le barème par défaut ci-dessus s\'applique à '
                .'toutes les puissances fiscales.')
            ->onlyOnForms();

        yield CollectionField::new('taskRates', 'Taux horaires par tâche')
            ->allowAdd()
            ->allowDelete()
            ->setEntryType(FiscalYearTaskRateType::class)
            ->setFormTypeOption('entry_options', ['fiscal_year' => $this->fiscalYearOfForm()])
            ->setHelp('Facultatif. Sans ligne ici, le taux horaire par défaut ci-dessus '
                .'s\'applique à toutes les tâches.')
            ->onlyOnForms();

        yield CollectionField::new('mileageRates', 'Barème par puissance fiscale')
            ->onlyOnDetail()
            ->formatValue(static function (mixed $value, FiscalYear $year): string {
                $parts = [];
                foreach ($year->getMileageRates() as $rate) {
                    $parts[] = $rate->getFiscalPower()->label().' : '.self::formatMilliEuros($rate->getMilliEurosPerKm());
                }

                return [] === $parts ? 'Aucun — le barème par défaut s\'applique' : implode(' · ', $parts);
            });

        yield CollectionField::new('taskRates', 'Taux horaires par tâche')
            ->onlyOnDetail()
            ->formatValue(static function (mixed $value, FiscalYear $year): string {
                $parts = [];
                foreach ($year->getTaskRates() as $rate) {
                    $parts[] = $rate->getTask()->getName().' : '.self::formatEuros($rate->getHourlyRateCents());
                }

                return [] === $parts ? 'Aucun — le taux par défaut s\'applique' : implode(' · ', $parts);
            });
    }

    /**
     * The écriture of the exercice: what actually gets booked.
     *
     * One centralising entry per family, dated on the exercice's close — not the
     * per-volunteer detail, which is a different question and now a different page. A
     * treasurer opening this should be able to copy six figures into their journal
     * without adding anything up.
     *
     * A custom action rather than a field template, because the page needs a query of
     * its own — the lines are not an association of FiscalYear, they are found by
     * date. #[AdminRoute] is required or linkToCrudAction() points nowhere; see
     * App\Controller\Admin\DeclarationCrudController.
     *
     * @param AdminContext<FiscalYear> $context
     */
    #[AdminRoute(path: '/{entityId}/ledger', name: 'ledger', options: ['methods' => ['GET']])]
    public function ledger(AdminContext $context): Response
    {
        $fiscalYear = $this->fiscalYearOf($context);

        return new Response($this->twig->render('admin/fiscal_year/ledger.html.twig', [
            'fiscal_year' => $fiscalYear,
            'summary' => $this->ledgerBuilder->build($fiscalYear)->summary(),
            'detail_url' => $this->urlFor(self::ACTION_LEDGER_DETAIL, $fiscalYear),
        ]));
    }

    /**
     * The same contributions, per volunteer — what justifies an individual reçu fiscal.
     *
     * @param AdminContext<FiscalYear> $context
     */
    #[AdminRoute(path: '/{entityId}/ledger/detail', name: 'ledger_detail', options: ['methods' => ['GET']])]
    public function ledgerDetail(AdminContext $context): Response
    {
        $fiscalYear = $this->fiscalYearOf($context);

        return new Response($this->twig->render('admin/fiscal_year/ledger_detail.html.twig', [
            'fiscal_year' => $fiscalYear,
            'ledger' => $this->ledgerBuilder->build($fiscalYear),
            'summary_url' => $this->urlFor(self::ACTION_LEDGER, $fiscalYear),
        ]));
    }

    /**
     * The exercice the form being built is about.
     *
     * The entry types need it to construct a rate for a row the user has just added, and
     * configureFields() has no argument carrying it — so it comes off the AdminContext, or is a
     * fresh one on the "new exercice" page.
     */
    private function fiscalYearOfForm(): FiscalYear
    {
        $instance = $this->getContext()?->getEntity()->getInstance();

        return $instance instanceof FiscalYear
            ? $instance
            : new FiscalYear($this->tenantContext->getOrganization());
    }

    /**
     * @param AdminContext<FiscalYear> $context
     */
    private function fiscalYearOf(AdminContext $context): FiscalYear
    {
        $fiscalYear = $context->getEntity()->getInstance();

        // FiscalYear IS TenantAware, so another association's exercice simply comes
        // back null through the filter. That is a 404, not an assertion failure — a
        // custom CRUD action is handed a null instance rather than being refused.
        if (!$fiscalYear instanceof FiscalYear) {
            throw new NotFoundHttpException('Fiscal year not found.');
        }

        return $fiscalYear;
    }

    private function urlFor(string $action, FiscalYear $fiscalYear): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction($action)
            ->setEntityId($fiscalYear->getId()->toRfc4122())
            ->generateUrl();
    }

    /**
     * Millièmes d'euro per km to a readable rate: 529 → "0,529 €/km".
     *
     * Integer arithmetic, like everywhere else on a money path.
     */
    private static function formatMilliEuros(int $milliEuros): string
    {
        return sprintf("%d,%03d\u{a0}€/km", intdiv($milliEuros, 1000), abs($milliEuros % 1000));
    }

    private static function formatEuros(int $cents): string
    {
        return sprintf("%d,%02d\u{a0}€/h", intdiv($cents, 100), abs($cents % 100));
    }
}
