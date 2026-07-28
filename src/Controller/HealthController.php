<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What Kubernetes probes, and the only route this application serves outside a
 * tenant or the backoffice.
 *
 * It exists because there is nothing else safe to probe. `/` is not routed at all
 * — every page lives under `/a/{organizationSlug}/…`, `/admin` or `/login` — so the
 * chart's original probe on `/` got a 404 forever and the pod never turned ready.
 *
 * DELIBERATELY DOES NOT TOUCH THE DATABASE. The same endpoint backs the liveness
 * probe, and a liveness probe that fails when the database blips would turn a
 * recoverable outage into a rolling restart of every pod — the application killed
 * for something it does not control. Whether the database is reachable is already
 * settled before this can answer at all: docker-entrypoint waits for it and runs
 * the migrations before FrankenPHP binds :80.
 *
 * Not a tenant URL either. Probing /a/<slug>/declaration would look more thorough,
 * but it cannot answer until an organization has been created — so the very first
 * deployment could never become ready — it would pin one association's slug into a
 * chart meant to serve many, and, on liveness, unticking that association's
 * "active" box in the backoffice would take the pods down with it.
 */
#[Route(path: '/health', name: 'health', methods: [Request::METHOD_GET])]
final readonly class HealthController
{
    public function __invoke(): Response
    {
        // Reaching here already proves what this endpoint claims: FrankenPHP is
        // serving, PHP runs, and the kernel and container booted.
        return new JsonResponse(['status' => 'ok'])
            // Never let a proxy or the CDN answer this on the pod's behalf.
            ->setPrivate()
            ->setMaxAge(0);
    }
}
