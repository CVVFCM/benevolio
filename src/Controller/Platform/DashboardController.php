<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office of the PLATFORM: creates and configures the associations that use
 * Benevolio, and their admin accounts.
 *
 * It is a separate dashboard from /admin on purpose. A super-admin is attached
 * to no organization, so App\Tenant\TenantRequestListener resolves no tenant and
 * leaves the Doctrine tenant filter disabled — this dashboard therefore sees
 * every tenant's rows by construction, and no code here has to remember to
 * bypass the filter.
 *
 * CONVENTION EXCEPTION: see App\Controller\Admin\DashboardController.
 */
#[AdminDashboard(
    routePath: '/platform',
    routeName: 'platform',
    allowedControllers: [
        OrganizationCrudController::class,
        UserCrudController::class,
    ],
)]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('platform/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Benevolio — plateforme')
            ->setTranslationDomain('admin')
            ->setLocales(['fr'])
            // Same reason as the tenant dashboard: behind a TLS-terminating Gateway,
            // EasyAdmin's absolute URLs come out as `http://` and a form posting to one is
            // downgraded to GET by the edge redirect. See App\Controller\Admin\DashboardController.
            ->generateRelativeUrls();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('menu.dashboard', 'fa fa-home');
        // EasyAdmin 5 dropped MenuItem::linkToCrud(): menu items point at the
        // CRUD controller, not at the entity.
        yield MenuItem::linkTo(OrganizationCrudController::class, 'menu.organizations', 'fa fa-building');
        yield MenuItem::linkTo(UserCrudController::class, 'menu.users', 'fa fa-user-shield');
    }
}
