<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

use App\Exception\ExceptionInterface;
use LogicException;

/**
 * Thrown when code asks for the current tenant on a request where none could be
 * resolved — a platform (/platform) route, a CLI command, or a bug in the
 * listener ordering.
 *
 * It is a LogicException, not a RuntimeException: reading the tenant somewhere
 * it cannot exist is a programming error, and should not be caught and papered
 * over. Use TenantContext::tryGetOrganization() where absence is legitimate.
 */
final class TenantNotResolvedException extends LogicException implements ExceptionInterface
{
    public static function create(): self
    {
        return new self(
            'No tenant is resolved for the current request. '
            .'This happens on platform-wide routes and in CLI context, where no organization applies. '
            .'Use TenantContext::tryGetOrganization() if absence is expected here.',
        );
    }
}
