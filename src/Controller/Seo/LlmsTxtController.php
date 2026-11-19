<?php

declare(strict_types=1);

namespace App\Controller\Seo;

use App\Guide\GuideCatalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * A plain-text map of the site for language-model crawlers (llmstxt.org): what Benevolio is
 * and does, in a few facts, and links to the guides. Markdown, as the proposal asks.
 *
 * Anonymous and publicly cached; nothing here may read the token. See AGENTS.md, *SEO & GEO*.
 */
#[Route(path: '/llms.txt', name: 'llms_txt', methods: [Request::METHOD_GET])]
final readonly class LlmsTxtController
{
    public function __construct(
        private Environment $twig,
        private GuideCatalog $guides,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('seo/llms.txt.twig', [
            'guides' => $this->guides->all(),
        ]), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ])
            ->setPublic()
            ->setMaxAge(SitemapController::MAX_AGE);
    }
}
