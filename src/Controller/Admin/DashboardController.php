<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Platform\OrganizationCrudController;
use App\Controller\Platform\UserCrudController as PlatformUserCrudController;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

/**
 * Back-office of ONE association. Everything reachable from here is scoped to
 * the admin's own organization by the Doctrine tenant filter, which
 * App\Tenant\TenantRequestListener armed from the logged-in account — except
 * DeclarationAction, which is not TenantAware and scopes itself (see
 * App\Controller\Admin\DeclarationActionCrudController).
 *
 * CONVENTION EXCEPTION: AGENTS.md forbids extending AbstractController, but
 * EasyAdmin dashboards must extend AbstractDashboardController, which extends
 * it. This is the documented exception; plain application controllers stay
 * invokable and dependency-injected (see App\Controller\LoginController).
 *
 * deniedControllers is load-bearing, not decoration: EasyAdmin registers every
 * CRUD controller under every dashboard unless told otherwise, so without it the
 * platform CRUDs would also answer on /admin/organization and /admin/user. The
 * ROLE_SUPER_ADMIN checks on those controllers would still refuse an organization
 * admin, but a super-admin would reach the platform CRUDs through the tenant
 * dashboard, where the Doctrine filter is armed. Any CRUD added here must be
 * tenant-scoped.
 *
 * App\Controller\Admin\UserCrudController is another: User is not TenantAware either
 * (filtering it would break authentication), so it scopes itself like DeclarationAction.
 *
 * The one exception is App\Controller\Admin\MyOrganizationCrudController, which acts on
 * Organization — the tenant itself, and therefore not TenantAware. It scopes itself, on
 * every entry point; read its docblock before adding anything like it.
 */
#[AdminDashboard(
    routePath: '/admin',
    routeName: 'admin',
    deniedControllers: [
        OrganizationCrudController::class,
        // The platform's, not App\Controller\Admin\UserCrudController: aliased
        // because the two share a short name.
        PlatformUserCrudController::class,
    ],
)]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'organization' => $this->tenantContext->getOrganization(),
            // The one action a treasurer comes looking for once a year, and would otherwise
            // have to find inside a CRUD they have no other reason to open.
            'generate_receipts_url' => $this->adminUrlGenerator
                ->setController(ReceiptCrudController::class)
                ->setAction(ReceiptCrudController::ACTION_CHOOSE_YEAR)
                ->generateUrl(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // Escaped: EasyAdmin prints the title raw, on every page, and the name
            // is editable from « Mon association ». Unescaped, any admin could run
            // script in the session of whoever opens this back office — including a
            // platform super-admin using « Se connecter en tant que ».
            ->setTitle(htmlspecialchars($this->tenantContext->getOrganization()->getName()))
            ->setTranslationDomain('admin')
            ->setLocales(['fr'])
            // Paths, not absolute URLs, and this is load-bearing in production.
            //
            // TLS terminates at the Gateway, so the pod sees plain HTTP. EasyAdmin's absolute
            // URLs were therefore built with scheme `http://`, and the « Générer et envoyer »
            // form posted to http: the browser warned that the data would travel in the
            // clear, the edge answered with a redirect to https, the redirect turned the POST
            // into a GET, and a POST-only route replied 405. A relative action inherits the
            // scheme of the page it was served on and cannot do that.
            //
            // SYMFONY_TRUSTED_PROXIES fixes the *cause* (see the Helm chart); this makes the
            // admin immune to it either way.
            ->generateRelativeUrls();
    }

    /**
     * app.css is deliberately absent: it is the public volunteer stylesheet, and its
     * :root token overrides and body rules would fight EasyAdmin's own design.
     * admin.css carries only the markup this project contributes itself.
     */
    public function configureAssets(): Assets
    {
        // driver.js's stylesheet before admin.css, which restyles the tour; the `admin`
        // entrypoint runs the tour controller (templates/admin/dashboard.html.twig).
        return Assets::new()
            ->addCssFile('vendor/driver.js/dist/driver.css')
            ->addCssFile('styles/admin.css')
            ->addAssetMapperEntry('admin');
    }

    /**
     * EasyAdmin's menu without its « exit impersonation » entry. That entry is a GET
     * link to `?_switch_user=_exit`, which App\Security\ImpersonationListener refuses
     * — a switch, in or out, must be a POST with a token. The banner in
     * templates/bundles/EasyAdminBundle/layout.html.twig is the way out instead.
     */
    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)->setMenuItems([
            MenuItem::section(),
            MenuItem::linkToLogout(t('user.sign_out', domain: 'EasyAdminBundle'), 'internal:sign-out'),
        ]);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('menu.dashboard', 'fa fa-home');

        yield MenuItem::section('menu.declarations');
        // The tour-* classes are what the dashboard's guided tour points at
        // (templates/admin/tour/_dashboard.html.twig). UrlMenuItem has no
        // setHtmlAttribute(), so a class it is, on all four alike.
        yield MenuItem::linkTo(DeclarationCrudController::class, 'menu.declaration_list', 'fa fa-file-lines')
            ->setCssClass('tour-declarations');
        yield MenuItem::linkTo(DeclarationActionCrudController::class, 'menu.declaration_actions', 'fa fa-list-check');
        yield MenuItem::linkTo(PersonCrudController::class, 'menu.people', 'fa fa-users');

        yield MenuItem::linkTo(ReceiptCrudController::class, 'menu.receipts', 'fa fa-file-invoice')
            ->setCssClass('tour-receipts');

        yield MenuItem::section('menu.configuration');
        yield MenuItem::linkTo(TaskCrudController::class, 'menu.tasks', 'fa fa-tags');
        yield MenuItem::linkTo(ActivityCrudController::class, 'menu.activities', 'fa fa-calendar-check');
        yield MenuItem::linkTo(FiscalYearCrudController::class, 'menu.fiscal_years', 'fa fa-calendar-days')
            ->setCssClass('tour-fiscal-years');
        yield MenuItem::linkTo(UserCrudController::class, 'menu.users', 'fa fa-user-shield');

        // linkToUrl, not linkTo: MenuItem::linkTo() points at a controller's index, and
        // MyOrganizationCrudController has none — there is one association to show, the
        // tenant's own. (EasyAdmin 5 dropped MenuItem::linkToCrud(), which is what would
        // otherwise carry an action and an entity id.)
        yield MenuItem::linkToUrl(
            'menu.my_organization',
            'fa fa-building',
            $this->adminUrlGenerator
                ->setController(MyOrganizationCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($this->tenantContext->getOrganization()->getId()->toRfc4122())
                ->generateUrl(),
        )->setCssClass('tour-my-organization');
    }
}
