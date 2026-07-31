<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * An uploaded signature that cannot become one.
 *
 * Every case carries a **French message meant for the treasurer**, because that is where it
 * ends up: App\Entity\Organization catches this while the form is binding and turns it into a
 * violation on the file field. Nothing here is a programming error — someone picked the wrong
 * file, which is an ordinary thing to do.
 */
final class SignatureImageException extends InvalidArgumentException implements ExceptionInterface
{
    private function __construct(
        string $message,
        public readonly string $userMessage,
    ) {
        parent::__construct($message);
    }

    public static function notAnImage(): self
    {
        return new self(
            'The uploaded bytes are not a decodable image.',
            'Ce fichier n\'est pas une image lisible. Déposez un PNG ou un JPEG.',
        );
    }

    public static function unsupportedType(string $mimeType): self
    {
        return new self(
            sprintf('Unsupported signature image type "%s".', $mimeType),
            'Déposez une image PNG ou JPEG.',
        );
    }

    /**
     * Refused on dimensions rather than on weight: PNG compresses flat artwork so well that a
     * small file can hold an enormous canvas, and GD would allocate four bytes per pixel of it.
     */
    public static function tooManyPixels(int $width, int $height): self
    {
        return new self(
            sprintf('Signature image is %d×%d pixels, past the decoding budget.', $width, $height),
            sprintf(
                'Cette image fait %d × %d pixels, c\'est trop grand pour être traitée. '
                .'Réduisez-la avant de la déposer : une signature n\'a pas besoin de plus de '
                .'1 000 pixels de côté.',
                $width,
                $height,
            ),
        );
    }

    public static function cannotResize(): self
    {
        return new self(
            'GD could not resize or re-encode the signature image.',
            'Le redimensionnement de cette image a échoué. Essayez un autre fichier.',
        );
    }
}
