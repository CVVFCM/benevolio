<?php

declare(strict_types=1);

namespace App\Declaration\Exception;

use App\Exception\ExceptionInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use function sprintf;

/**
 * Thrown when the event or action a volunteer picked cannot be found at submit time —
 * deleted while they were filling the form, or carried over from another association's
 * draft. Not caught, for the reasons given on TaskNoLongerAvailableException.
 */
final class ActivityNoLongerAvailableException extends RuntimeException implements ExceptionInterface
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf(
            'Activity "%s" is not available for the current organization; it was deleted, '
            .'or the draft carries one from another association.',
            $id->toRfc4122(),
        ));
    }
}
