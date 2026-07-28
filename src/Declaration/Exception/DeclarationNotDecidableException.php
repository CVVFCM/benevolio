<?php

declare(strict_types=1);

namespace App\Declaration\Exception;

use App\Entity\Declaration;
use App\Exception\ExceptionInterface;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a bulk verdict cannot be applied to a whole declaration.
 *
 * A RuntimeException, not a LogicException: this is a legitimate runtime state
 * that the back-office is expected to catch and report to the treasurer, not a
 * programming error.
 */
final class DeclarationNotDecidableException extends RuntimeException implements ExceptionInterface
{
    public static function alreadyDecided(Declaration $declaration): self
    {
        return new self(sprintf(
            'This declaration is already %s.',
            $declaration->getState()->label(),
        ));
    }

    public static function hasNoAction(): self
    {
        return new self('This declaration has no action to decide on.');
    }

    /**
     * The mixed-basket case: some lines already went the other way, so the whole
     * declaration cannot take a single verdict. See App\State\DeclarationState.
     */
    public static function linesDisagree(): self
    {
        return new self(
            'Some lines of this declaration have already been decided the other way, '
            .'so no single verdict applies to the whole of it. Decide the remaining lines individually.',
        );
    }
}
