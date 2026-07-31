<?php

declare(strict_types=1);

namespace App\Organization;

use App\Entity\OrganizationSignature;
use App\Exception\SignatureImageException;
use Imagine\Exception\Exception as ImagineException;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function base64_encode;
use function file_get_contents;
use function getimagesizefromstring;
use function in_array;
use function max;

/**
 * Turns an uploaded file into a stored signature — scaled down to what a receipt can use.
 *
 * **What is accepted is not what is stored.** 16 MB is accepted from the browser so a treasurer
 * can drop in whatever their scanner produced; anything longer than STORED_MAX_EDGE_PX on its
 * long side is scaled before it reaches the database. Kept as uploaded, a 16 MB signature would
 * be 21 MB of base64 in the row, the same again inside every receipt overlay, a receipt PDF per
 * volunteer heavier than most relays accept as an attachment, and a request over PHP's memory
 * limit.
 *
 * Imagine over raw GD calls: the resize-and-re-encode dance (aspect ratio, alpha flags, output
 * buffering) is exactly the sort of thing worth not writing twice. The Gd driver is aliased in
 * services.yaml — ext-imagick is not installed.
 */
final readonly class SignatureFactory
{
    /**
     * The long side of what gets stored, in pixels.
     *
     * The CERFA gives the signature a 14 mm box, so 1 000 px is over 1 800 dpi there — past
     * anything a printer or a reader can use. Scaling to it turns a 16 MB scan into tens of
     * kilobytes.
     */
    public const int STORED_MAX_EDGE_PX = 1000;

    /**
     * The most pixels this will decode, as width × height.
     *
     * A guard on *dimensions*, which the byte cap does not give: PNG compresses flat artwork
     * enormously, so a few-kilobyte file can hold a 12 000 × 12 000 canvas — and a decoder
     * holds an image at 4 bytes per pixel, which is 576 MB before any scaling. 16 megapixels is
     * a 4 000 × 4 000 scan and costs 64 MB decoded, which php.ini's memory_limit covers.
     */
    public const int MAX_PIXELS = 16_000_000;

    /**
     * Quality for a JPEG that had to be re-encoded. 85 is the usual "no visible loss" point,
     * and a signature is not a photograph anyone will pixel-peep.
     */
    private const int JPEG_QUALITY = 85;

    public function __construct(
        private ImagineInterface $imagine,
    ) {
    }

    /**
     * @throws SignatureImageException when the file is not a usable PNG or JPEG, holds more
     *                                 pixels than can be decoded safely, or cannot be re-encoded
     */
    public function fromUploadedFile(UploadedFile $file): OrganizationSignature
    {
        // An upload PHP threw away — over `upload_max_filesize`, unwritable temp directory,
        // connection cut mid-transfer — arrives with an EMPTY path. Symfony's FileType adds the
        // right error to the field but deliberately does not clear the data, so this has to
        // check for itself: reading it would throw "The "" file does not exist or is not
        // readable." and turn a too-large file into a 500.
        if (!$file->isValid()) {
            throw SignatureImageException::uploadFailed($file->getError());
        }

        $bytes = file_get_contents($file->getPathname());

        if (false === $bytes) {
            throw SignatureImageException::uploadFailed($file->getError());
        }

        return $this->fromBytes($bytes, $file->getClientOriginalName());
    }

    /**
     * @throws SignatureImageException
     */
    public function fromBytes(string $bytes, ?string $originalFilename = null): OrganizationSignature
    {
        $size = getimagesizefromstring($bytes);

        if (false === $size) {
            throw SignatureImageException::notAnImage();
        }

        [$width, $height] = $size;

        if (!in_array($size['mime'], OrganizationSignature::ALLOWED_MIME_TYPES, true)) {
            throw SignatureImageException::unsupportedType($size['mime']);
        }

        // Before the decode, not after: a decoder allocates four bytes per pixel the moment it
        // reads the file, so a check afterwards would come too late to prevent anything.
        if ($width * $height > self::MAX_PIXELS) {
            throw SignatureImageException::tooManyPixels($width, $height);
        }

        if (max($width, $height) <= self::STORED_MAX_EDGE_PX) {
            // Already usable, so kept byte for byte: re-encoding would cost quality and alpha
            // for nothing.
            return new OrganizationSignature($size['mime'], base64_encode($bytes), $originalFilename);
        }

        return new OrganizationSignature(
            $size['mime'],
            base64_encode($this->scaleDown($bytes, $size['mime'])),
            $originalFilename,
        );
    }

    /**
     * Scales the image inside a STORED_MAX_EDGE_PX square and re-encodes it **in its own
     * format**.
     *
     * Not "always PNG", which is what this did first and got wrong: PNG is right for line art
     * and for anything with transparency, and badly wrong for a photograph — a 3 000 × 3 000
     * photo of a signed page came out of the resize at 650 KB as PNG against roughly 120 KB as
     * JPEG, and every receipt carries that weight. A JPEG has no alpha to protect, so keeping
     * the format it arrived in loses nothing and stores a fifth of the bytes.
     *
     * THUMBNAIL_INSET fits the image inside the box and keeps its aspect ratio.
     *
     * @throws SignatureImageException
     */
    private function scaleDown(string $bytes, string $mimeType): string
    {
        try {
            $thumbnail = $this->imagine
                ->load($bytes)
                ->thumbnail(
                    new Box(self::STORED_MAX_EDGE_PX, self::STORED_MAX_EDGE_PX),
                    ImageInterface::THUMBNAIL_INSET,
                );

            return 'image/jpeg' === $mimeType
                ? $thumbnail->get('jpeg', ['jpeg_quality' => self::JPEG_QUALITY])
                : $thumbnail->get('png');
        } catch (ImagineException $e) {
            throw SignatureImageException::cannotResize($e);
        }
    }
}
