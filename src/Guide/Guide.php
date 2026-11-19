<?php

declare(strict_types=1);

namespace App\Guide;

use DateTimeImmutable;

/**
 * One guide under /guides: what a search result and a link preview show (title,
 * description), and the date its facts were last checked — printed on the page, sent as
 * `dateModified` and as the sitemap's `lastmod`. Change the date when the text changes.
 */
final readonly class Guide
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public DateTimeImmutable $updatedOn,
    ) {
    }

    public function template(): string
    {
        return 'guides/'.$this->slug.'.html.twig';
    }
}
