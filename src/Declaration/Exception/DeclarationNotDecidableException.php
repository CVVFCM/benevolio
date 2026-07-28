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
 *
 * Carries two messages. The exception message is English and developer-facing,
 * like every other exception here; userMessage() is the French sentence the
 * back-office shows, because the UI is French-only and an English flash message
 * in it would be a bug.
 */
final class DeclarationNotDecidableException extends RuntimeException implements ExceptionInterface
{
    private function __construct(
        string $message,
        private readonly string $userMessage,
    ) {
        parent::__construct($message);
    }

    public static function alreadyDecided(Declaration $declaration): self
    {
        return new self(
            sprintf('This declaration is already %s.', $declaration->getState()->value),
            sprintf('Cette déclaration est déjà %s.', mb_strtolower($declaration->getState()->label())),
        );
    }

    public static function awaitingConfirmation(): self
    {
        return new self(
            'This declaration has not been confirmed by the volunteer yet.',
            'Cette déclaration n\'a pas encore été confirmée par le bénévole : '
            .'le lien envoyé par courriel n\'a pas été utilisé.',
        );
    }

    public static function hasNoAction(): self
    {
        return new self(
            'This declaration has no action to decide on.',
            'Cette déclaration ne contient aucune action à traiter.',
        );
    }

    /**
     * The mixed-basket case: some lines already went the other way, so the whole
     * declaration cannot take a single verdict. See App\State\DeclarationState.
     */
    public static function linesDisagree(): self
    {
        return new self(
            'Some lines of this declaration have already been decided the other way, '
            .'so no single verdict applies to the whole of it.',
            'Certaines actions de cette déclaration ont déjà été traitées dans l\'autre sens : '
            .'aucun verdict global ne s\'applique. Traitez les actions restantes une par une.',
        );
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
}
