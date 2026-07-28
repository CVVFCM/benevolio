<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * Thrown by a value object whose constructor arguments break its invariants.
 *
 * An InvalidArgumentException rather than a validation mechanism: value objects
 * are constructed from data that has *already* been validated (by Symfony
 * constraints on a form DTO, or by a previous write). Reaching this exception
 * means something bypassed that validation, which is a programming error.
 *
 * User-facing field-level errors are the job of the form layer, not of this
 * exception — see App\Form\Declaration\DeclarationDraft.
 */
final class InvalidValueObjectException extends InvalidArgumentException implements ExceptionInterface
{
    public static function create(string $valueObject, string $reason): self
    {
        return new self(sprintf('Cannot build %s: %s', $valueObject, $reason));
    }
}
