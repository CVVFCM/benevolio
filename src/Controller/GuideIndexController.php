<?php

declare(strict_types=1);

namespace App\Controller;

use App\Guide\GuideCatalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The list of guides — see App\Guide\GuideCatalog.
 */
#[Route(path: '/guides', name: 'guides', methods: [Request::METHOD_GET])]
final readonly class GuideIndexController
{
    public function __construct(
        private Environment $twig,
        private GuideCatalog $guides,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('guides/index.html.twig', [
            'guides' => $this->guides->all(),
        ]))
            ->setPublic()
            ->setMaxAge(GuideController::MAX_AGE);
    }
}
