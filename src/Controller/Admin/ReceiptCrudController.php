<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Receipt;
use App\Form\ReceiptYearType;
use App\Receipt\ReceiptStorage;
use App\Receipt\YearlyReceiptRun;
use App\Repository\DeclarationActionRepository;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

use function assert;
use function sprintf;

/**
 * The reçus fiscaux this association has issued, and the page that issues them.
 *
 * A receipt covers one volunteer for one **civil year** and is created only here, by
 * App\Receipt\YearlyReceiptRun. Nothing happens when a declaration is validated: a year's
 * total is not known until the year is over, and no single declaration decides it.
 *
 * Receipt is TenantAware, so OrganizationFilter scopes the index and the detail; the
 * generation actions take the tenant from TenantContext, because a run is about the whole
 * association and not about a row.
 *
 * NEW, EDIT and DELETE are disabled: a numbered tax document is issued by the run, never
 * typed in by hand, and never deleted — the numbering has to stay continuous, and a
 * volunteer may already have filed a tax return quoting it.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 *
 * @extends AbstractCrudController<Receipt>
 */
#[IsGranted('ROLE_ADMIN')]
final class ReceiptCrudController extends AbstractCrudController
{
    public const string ACTION_CHOOSE_YEAR = 'chooseYear';
    private const string ACTION_GENERATE = 'generate';
    private const string ACTION_DOWNLOAD = 'download';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly YearlyReceiptRun $run,
        private readonly DeclarationActionRepository $actions,
        private readonly ReceiptStorage $storage,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly FormFactoryInterface $forms,
        private readonly ClockInterface $clock,
        private readonly Environment $twig,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Receipt::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Reçu fiscal')
            ->setEntityLabelInPlural('Reçus fiscaux')
            // Most recent first, and within a year the highest number first: that is the
            // order someone looking for "the one I just issued" expects.
            ->setDefaultSort(['year' => 'DESC', 'number' => 'DESC'])
            ->setSearchFields(['number', 'volunteerName'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Un reçu par bénévole et par année civile. Les numéros forment une série '
                .'continue par association et ne sont jamais réutilisés.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        $chooseYear = Action::new(self::ACTION_CHOOSE_YEAR, 'Générer les reçus d\'une année', 'fa fa-file-invoice')
            ->linkToCrudAction(self::ACTION_CHOOSE_YEAR)
            ->createAsGlobalAction();

        $download = Action::new(self::ACTION_DOWNLOAD, 'Télécharger', 'fa fa-download')
            ->linkToCrudAction(self::ACTION_DOWNLOAD);

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $chooseYear)
            ->add(Crud::PAGE_INDEX, $download);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('number', 'N° d\'ordre');

        yield IntegerField::new('year', 'Année civile')
            // Plain, not grouped: 2025, never "2 025".
            ->setNumberFormat('%d');

        yield AssociationField::new('person', 'Bénévole');

        // The name as printed on the document, which is not necessarily the volunteer's
        // name today — see App\Entity\Receipt.
        yield TextField::new('volunteerName', 'Nom imprimé')
            ->onlyOnDetail();

        yield TextField::new('volunteerAddress', 'Adresse imprimée')
            ->onlyOnDetail();

        yield MoneyField::new('amountCents', 'Montant')
            ->setCurrency('EUR')
            ->setNumDecimals(2)
            ->setHelp('Frais abandonnés uniquement. Le bénévolat valorisé n\'ouvre aucun droit.');

        yield DateTimeField::new('issuedAt', 'Émis le');

        yield TextField::new('storagePath', 'Fichier')
            ->onlyOnDetail();
    }

    /**
     * The year form. A GET page, deliberately separate from the POST that runs the batch.
     *
     * #[AdminRoute] is required, not optional: EasyAdmin 5 creates no route for a custom
     * CRUD action without it, and linkToCrudAction() would point nowhere.
     *
     * @param AdminContext<Receipt> $context
     */
    #[AdminRoute(path: '/batch/choose-year', name: 'choose_year', options: ['methods' => ['GET']])]
    public function chooseYear(AdminContext $context): Response
    {
        return new Response($this->twig->render('admin/receipt/choose_year.html.twig', [
            'form' => $this->yearForm()->createView(),
        ]));
    }

    /**
     * Runs the batch and shows what it did.
     *
     * POST only: issuing numbered tax documents and emailing them is not something a link
     * — or a browser prefetching one — should be able to trigger.
     *
     * @param AdminContext<Receipt> $context
     */
    #[AdminRoute(path: '/batch/generate', name: 'generate', options: ['methods' => ['POST']])]
    public function generate(AdminContext $context): Response
    {
        $request = $context->getRequest();
        $form = $this->yearForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Re-render the form with its errors, and say so with 422 rather than 200: the
            // request was understood and refused.
            return new Response(
                $this->twig->render('admin/receipt/choose_year.html.twig', ['form' => $form->createView()]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Valid, so the data is there and the year is an int — the choices are built from
        // int values, and ChoiceType refuses anything not among them.
        // Valid, so there is data: the shape comes from ReceiptYearType's own generic, and
        // ChoiceType has already refused anything but one of the offered years.
        $data = $form->getData();
        assert(null !== $data);

        $report = $this->run->run($this->tenantContext->getOrganization(), $data['year']);

        return new Response($this->twig->render('admin/receipt/report.html.twig', [
            'report' => $report,
            'index_url' => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl(),
        ]));
    }

    /**
     * The PDF, straight out of object storage.
     *
     * Receipt is TenantAware, so another association's id simply comes back null through the
     * filter — a 404, not an assertion failure, because a custom CRUD action is handed a
     * null instance rather than being refused.
     *
     * @param AdminContext<Receipt> $context
     */
    #[AdminRoute(path: '/{entityId}/download', name: 'download', options: ['methods' => ['GET']])]
    public function download(AdminContext $context): Response
    {
        $receipt = $context->getEntity()->getInstance();

        if (!$receipt instanceof Receipt) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        try {
            $pdf = $this->storage->read($receipt->getStoragePath());
        } catch (FilesystemException) {
            // The row is the record and it survives its object — see App\Entity\Receipt. A
            // missing file is worth reporting as missing, not as a 500.
            throw new NotFoundHttpException(sprintf('The PDF for receipt %s is not in storage.', $receipt->getNumber()));
        }

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('recu-fiscal-%d-%s.pdf', $receipt->getYear(), $receipt->getNumber()),
        ));

        return $response;
    }

    /**
     * The year form, pointed at the POST action that runs the batch.
     *
     * The generic matches ReceiptYearType's own TData, and it has to be written out: the
     * template on FormInterface is invariant, so `FormInterface<mixed>` is not a supertype
     * of this and PHPStan says so.
     *
     * @return FormInterface<array{year: int, partialYearAcknowledged: bool}|null>
     */
    private function yearForm(): FormInterface
    {
        $organization = $this->tenantContext->getOrganization();
        $years = $this->actions->findYearsWithValidatedActions($organization);
        $currentYear = (int) $this->clock->now()->format('Y');

        return $this->forms->create(ReceiptYearType::class, null, [
            'years' => $years,
            'current_year' => $currentYear,
            'action' => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(self::ACTION_GENERATE)
                ->generateUrl(),
        ]);
    }
}
