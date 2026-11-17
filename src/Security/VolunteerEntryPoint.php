<?php

declare(strict_types=1);

namespace App\Security;

use App\Tenant\UrlPrefixTenantResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

use function is_string;

/**
 * An anonymous visitor on a volunteer page is sent to that association's « Se connecter ».
 */
final readonly class VolunteerEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $slug = $request->attributes->get(UrlPrefixTenantResolver::ROUTE_ATTRIBUTE);

        if (!is_string($slug)) {
            throw new NotFoundHttpException();
        }

        return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $slug]));
    }
}
