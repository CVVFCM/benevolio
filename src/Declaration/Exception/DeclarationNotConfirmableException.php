<?php

declare(strict_types=1);

namespace App\Declaration\Exception;

use App\Entity\Declaration;
use App\Exception\ExceptionInterface;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a back-office account tries to confirm a declaration that is not
 * waiting for it — typically because the volunteer clicked the link in between.
 *
 * Same shape as DeclarationNotDecidableException: an English message for
 * developers, and a French one for the flash message.
 */
final class DeclarationNotConfirmableException extends RuntimeException implements ExceptionInterface
{
    private function __construct(
        string $message,
        private readonly string $userMessage,
    ) {
        parent::__construct($message);
    }

    public static function notAwaitingConfirmation(Declaration $declaration): self
    {
        return new self(
            sprintf('This declaration is not awaiting confirmation, it is %s.', $declaration->getState()->value),
            sprintf(
                'Cette déclaration n\'attend plus de confirmation : elle est déjà %s.',
                mb_strtolower($declaration->getState()->label()),
            ),
        );
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
}
