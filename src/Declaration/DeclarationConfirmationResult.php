<?php

declare(strict_types=1);

namespace App\Declaration;

/**
 * What happened when a volunteer followed a confirmation link.
 *
 * Three outcomes rather than a boolean — plus "unknown token", which is a 404 and not
 * a case here — because each deserves a different page — and in particular because a second click is a SUCCESS, not an error: a
 * volunteer who clicks twice, or whose mail client prefetches the link, must not
 * be told something went wrong.
 */
enum DeclarationConfirmationResult
{
    case CONFIRMED;
    case ALREADY_CONFIRMED;
    case EXPIRED;
}
