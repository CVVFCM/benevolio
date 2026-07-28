<?php

declare(strict_types=1);

namespace App\Exception;

use LogicException;

use function sprintf;

/**
 * Thrown when an entity is used in a way its current state cannot support — for
 * instance authenticating a User whose email was never set.
 *
 * A LogicException rather than a RuntimeException: reaching this point means
 * validation was skipped, which is a programming error, not a runtime condition
 * to recover from.
 */
final class InvalidEntityStateException extends LogicException implements ExceptionInterface
{
    public static function missingProperty(object $entity, string $property): self
    {
        return new self(sprintf(
            'Cannot use %s: its "%s" is not set. It was probably built without going through validation.',
            $entity::class,
            $property,
        ));
    }
}
