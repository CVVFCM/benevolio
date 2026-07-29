<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Platform\OrganizationCrudController;
use App\Controller\Platform\UserCrudController;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
 */
#[AdminDashboard(
    routePath: '/admin',
    routeName: 'admin',
    deniedControllers: [
        OrganizationCrudController::class,
        UserCrudController::class,
    ],
)]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'organization' => $this->tenantContext->getOrganization(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle($this->tenantContext->getOrganization()->getName())
            ->setTranslationDomain('admin')
            ->setLocales(['fr']);
    }

    /**
     * app.css is deliberately absent: it is the public volunteer stylesheet, and its
     * :root token overrides and body rules would fight EasyAdmin's own design.
     * admin.css carries only the markup this project contributes itself.
     */
    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('styles/admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('menu.dashboard', 'fa fa-home');

        yield MenuItem::section('menu.declarations');
        yield MenuItem::linkTo(DeclarationCrudController::class, 'menu.declaration_list', 'fa fa-file-lines');
        yield MenuItem::linkTo(DeclarationActionCrudController::class, 'menu.declaration_actions', 'fa fa-list-check');
        yield MenuItem::linkTo(PersonCrudController::class, 'menu.people', 'fa fa-users');

        yield MenuItem::section('menu.configuration');
        yield MenuItem::linkTo(TaskCrudController::class, 'menu.tasks', 'fa fa-tags');
        yield MenuItem::linkTo(FiscalYearCrudController::class, 'menu.fiscal_years', 'fa fa-calendar-days');

        // Tax receipts (CERFA 2041-RD) land here in a following lot.
    }
}
