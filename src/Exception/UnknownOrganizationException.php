<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A public URL whose `{organizationSlug}` resolves no tenant: unknown, or no longer
 * active. A 404 — a visitor must not see the LogicException
 * TenantContext::getOrganization() would raise for a mistyped address.
 */
final class UnknownOrganizationException extends NotFoundHttpException implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Cette association n\'existe pas ou n\'accepte plus de déclarations.');
    }
}
