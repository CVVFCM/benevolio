<?php

declare(strict_types=1);

namespace App\Controller\Seo;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The crawlers' rules: every public page may be crawled, by every crawler — search
 * engines and AI crawlers alike, by decision — and the back offices not.
 *
 * Which pages are *indexed* is decided by the pages themselves (`indexable` in
 * base.html.twig), so /a/ is deliberately not disallowed: a crawler has to fetch a page to
 * read its noindex, and a disallowed URL linked from the home page can be indexed bare.
 *
 * A route rather than a file in public/, so the sitemap's address is absolute and follows
 * the domain the application is served on. See AGENTS.md, *SEO & GEO*.
 */
#[Route(path: '/robots.txt', name: 'robots_txt', methods: [Request::METHOD_GET])]
final readonly class RobotsTxtController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('seo/robots.txt.twig'), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ])
            ->setPublic()
            ->setMaxAge(SitemapController::MAX_AGE);
    }
}
