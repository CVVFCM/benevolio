<?php

declare(strict_types=1);

namespace App\Declaration\Exception;

use App\Exception\ExceptionInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use function sprintf;

/**
 * Thrown when the event type a volunteer picked cannot be found at submit time.
 *
 * Two ways to get here, both rare: the association deleted an as-yet-unused type
 * while someone was filling the form (a used one is protected by the FK), or the
 * session draft carries a type from another association — which the tenant-filtered
 * lookup in DeclarationSubmitter refuses to resolve.
 *
 * A RuntimeException: it is a real runtime race, not a programming error. It is
 * deliberately NOT caught and turned into a validation message — losing a whole
 * declaration to a silent fallback category would be worse than a visible failure,
 * and the case is rare enough not to design a recovery flow for until it actually
 * happens to somebody.
 */
final class EventTypeNoLongerAvailableException extends RuntimeException implements ExceptionInterface
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf(
            'Event type "%s" is not available for the current organization; it was deleted, '
            .'or the draft carries a type from another association.',
            $id->toRfc4122(),
        ));
    }
}
