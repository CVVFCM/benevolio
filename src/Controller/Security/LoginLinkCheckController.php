<?php

declare(strict_types=1);

namespace App\Controller\Security;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The address an emailed login link points to. Never actually runs: the firewall's
 * login_link authenticator intercepts the request (config/packages/security.yaml),
 * exactly as form_login does for LoginController's POST.
 */
#[Route(path: '/login/lien', name: 'login_link_check', methods: [Request::METHOD_GET])]
final readonly class LoginLinkCheckController
{
    public function __invoke(): never
    {
        throw new LogicException('The login_link authenticator should have intercepted this request.');
    }
}
