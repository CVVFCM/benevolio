<?php

declare(strict_types=1);

namespace App\Declaration;

/**
 * What a confirmation link amounts to, once it no longer simply waits for its button.
 *
 * Three outcomes rather than a boolean — plus "unknown token", which is a 404 and not a
 * case here — because each deserves a different page, and in particular because a second
 * visit is a SUCCESS, not an error: a volunteer who comes back to the link must not be
 * told something went wrong. A link still to confirm has no case: see
 * DeclarationConfirmer::look().
 */
enum DeclarationConfirmationResult
{
    case CONFIRMED;
    case ALREADY_CONFIRMED;
    case EXPIRED;
}
