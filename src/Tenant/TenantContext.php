<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Organization;
use App\Tenant\Exception\TenantNotResolvedException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the organization the current request belongs to.
 *
 * Filled by TenantRequestListener early in the request, then read by anything
 * that needs to scope itself to one association.
 *
 * IMPORTANT: this service carries per-request state while being a shared
 * (singleton) service. Under FrankenPHP worker mode the container survives
 * between requests, so it implements ResetInterface and is tagged kernel.reset
 * in config/services.yaml. Without that, request N would see request N-1's
 * tenant. Any future request-scoped state belongs here for the same reason.
 */
final class TenantContext implements ResetInterface
{
    private ?Organization $organization = null;

    /**
     * @throws TenantNotResolvedException when no tenant applies to this request
     */
    public function getOrganization(): Organization
    {
        return $this->organization ?? throw TenantNotResolvedException::create();
    }

    /**
     * For the callers where "no tenant" is a legitimate answer: platform-wide
     * routes and CLI context.
     */
    public function tryGetOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function hasOrganization(): bool
    {
        return null !== $this->organization;
    }

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function reset(): void
    {
        $this->organization = null;
    }
}
