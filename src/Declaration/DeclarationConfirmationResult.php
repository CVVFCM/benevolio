<?php

declare(strict_types=1);

namespace App\Declaration;

/**
 * What happened when a volunteer followed a confirmation link.
 *
 * Four distinct outcomes rather than a boolean, because each deserves a different
 * page — and in particular because a second click is a SUCCESS, not an error: a
 * volunteer who clicks twice, or whose mail client prefetches the link, must not
 * be told something went wrong.
 */
enum DeclarationConfirmationResult
{
    case CONFIRMED;
    case ALREADY_CONFIRMED;
    case EXPIRED;

    public function isSuccess(): bool
    {
        return self::CONFIRMED === $this || self::ALREADY_CONFIRMED === $this;
    }
}
