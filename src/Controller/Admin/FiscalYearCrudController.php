<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Accounting\LedgerBuilder;
use App\Entity\FiscalYear;
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
 * The interesting page is the ledger, not the form: see the `ledger` action below.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<FiscalYear>
 */
#[IsGranted('ROLE_ADMIN')]
final class FiscalYearCrudController extends AbstractCrudController
{
    private const string ACTION_LEDGER = 'ledger';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LedgerBuilder $ledgerBuilder,
        private readonly Environment $twig,
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

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $ledger)
            ->add(Crud::PAGE_DETAIL, $ledger);
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
     * The draft ledger: every validated contribution of this exercice, as PCG entries.
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
        $fiscalYear = $context->getEntity()->getInstance();

        // FiscalYear IS TenantAware, so another association's exercice simply comes
        // back null through the filter. That is a 404, not an assertion failure — a
        // custom CRUD action is handed a null instance rather than being refused.
        if (!$fiscalYear instanceof FiscalYear) {
            throw new NotFoundHttpException('Fiscal year not found.');
        }

        return new Response($this->twig->render('admin/fiscal_year/ledger.html.twig', [
            'fiscal_year' => $fiscalYear,
            'ledger' => $this->ledgerBuilder->build($fiscalYear),
        ]));
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
