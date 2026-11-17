<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The `volunteer` firewall's logout path. The firewall answers it before any controller
 * runs; App\Volunteer\VolunteerLogoutListener decides where it lands.
 */
#[Route(path: '/a/{organizationSlug}/deconnexion', name: 'volunteer_logout', methods: [Request::METHOD_POST])]
final readonly class LogoutController
{
    public function __invoke(): never
    {
        throw new LogicException('The volunteer firewall handles logout.');
    }
}
