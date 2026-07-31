<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;
use Throwable;

use function sprintf;

use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;

/**
 * An uploaded signature that cannot become one.
 *
 * Every case carries a **French message meant for the treasurer**, because that is where it
 * ends up: App\Controller\Admin\MyOrganizationCrudController catches this while the form is
 * binding and puts the message on the file field. Nothing here is a programming error — someone
 * picked the wrong file, which is an ordinary thing to do.
 */
final class SignatureImageException extends InvalidArgumentException implements ExceptionInterface
{
    private function __construct(
        string $message,
        public readonly string $userMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
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
     * small file can hold an enormous canvas, and a decoder would allocate four bytes per pixel
     * of it.
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

    /**
     * PHP discarded the upload before the application saw it — over `upload_max_filesize`, an
     * unwritable temporary directory, a transfer cut short. The file arrives with an empty
     * path, and reading it would throw from deep inside the MIME guesser.
     */
    public static function uploadFailed(int $errorCode): self
    {
        return new self(
            sprintf('The upload failed with PHP error code %d.', $errorCode),
            UPLOAD_ERR_INI_SIZE === $errorCode || UPLOAD_ERR_FORM_SIZE === $errorCode
                ? 'Ce fichier est trop volumineux : 16 Mo maximum.'
                : 'Le dépôt du fichier a échoué. Réessayez.',
        );
    }

    public static function cannotResize(?Throwable $previous = null): self
    {
        return new self(
            'The signature image could not be resized or re-encoded.',
            'Le redimensionnement de cette image a échoué. Essayez un autre fichier.',
            $previous,
        );
    }
}
