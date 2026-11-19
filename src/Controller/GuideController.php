<?php

declare(strict_types=1);

namespace App\Controller;

use App\Guide\GuideCatalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * One guide — see App\Guide\GuideCatalog. Anonymous and publicly cached, like the home
 * page: nothing here may read the security token.
 */
#[Route(path: '/guides/{slug}', name: 'guide', requirements: ['slug' => '[a-z0-9-]+'], methods: [Request::METHOD_GET])]
final readonly class GuideController
{
    public const int MAX_AGE = 3600;

    public function __construct(
        private Environment $twig,
        private GuideCatalog $guides,
    ) {
    }

    public function __invoke(string $slug): Response
    {
        $guide = $this->guides->find($slug) ?? throw new NotFoundHttpException('No such guide.');

        return new Response($this->twig->render($guide->template(), [
            'guide' => $guide,
            'guides' => $this->guides->all(),
        ]))
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);
    }
}
