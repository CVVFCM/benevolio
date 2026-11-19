<?php

declare(strict_types=1);

namespace App\Controller\Seo;

use App\Guide\GuideCatalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The indexable pages: the home page, /guides and each guide, with the date its facts were
 * last checked. Every URL absolute, built from the request. See AGENTS.md, *SEO & GEO*.
 *
 * Anonymous and publicly cached, like the home page: nothing here may read the token.
 */
#[Route(path: '/sitemap.xml', name: 'sitemap_xml', methods: [Request::METHOD_GET])]
final readonly class SitemapController
{
    /** A day: the three crawler files change only with a deployment. */
    public const int MAX_AGE = 86400;

    public function __construct(
        private Environment $twig,
        private GuideCatalog $guides,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('seo/sitemap.xml.twig', [
            'guides' => $this->guides->all(),
        ]), Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ])
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);
    }
}
