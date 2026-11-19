<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * « C'est gratuit »: what Benevolio costs — nothing, ever — and how to support it if one
 * wishes. Anonymous and publicly cached, like the home page: nothing here may read the token.
 */
#[Route(path: '/gratuit', name: 'pricing', methods: [Request::METHOD_GET])]
final readonly class PricingController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('site/pricing.html.twig'))
            ->setPublic()
            ->setMaxAge(GuideController::MAX_AGE);
    }
}
