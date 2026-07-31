<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\SignatureImageException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function base64_encode;
use function getimagesizefromstring;
use function imagealphablending;
use function imagecreatefromstring;
use function imagepng;
use function imagesavealpha;
use function imagescale;
use function in_array;
use function intdiv;
use function max;
use function ob_get_clean;
use function ob_start;
use function round;
use function sprintf;
use function strlen;

/**
 * The association's signature, as it is stamped onto a reçu fiscal.
 *
 * **A table of its own, not columns on Organization.** App\Tenant\TenantRequestListener
 * hydrates the tenant's Organization on every single request; a base64 image sitting in
 * that row would be read every time, for the two pages a year that need it. Organization
 * holds the *owning* side of the relation, so hydrating it reads only `signature_id` and
 * Doctrine loads these bytes lazily, when something actually asks for them. (The inverse
 * side would not do that — Doctrine resolves an inverse one-to-one with an extra query.)
 *
 * Base64 in a TEXT column rather than `bytea`: the value is consumed as a `data:` URI by
 * App\Receipt\ReceiptValues, and ORM 3 hydrates a BLOB as a stream resource, which buys
 * nothing here and costs clarity. It is 33% larger on disk than the bytes; for a signature
 * that is a rounding error.
 *
 * Nothing here is tenant-aware. It hangs off the tenant itself, and is only ever reached
 * through the Organization that owns it.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_signature')]
class OrganizationSignature
{
    public const int MIME_TYPE_MAX_LENGTH = 64;
    public const int ORIGINAL_FILENAME_MAX_LENGTH = 255;

    /**
     * The largest file accepted from the browser, in bytes.
     *
     * 16 MB, so a treasurer can drop a phone photo or a flatbed scan in without thinking
     * about it. `upload_max_filesize` and `post_max_size` are raised to match in
     * .infra/docker/php/conf.d — left at PHP's 2M default the browser field would just go
     * blank with no message at all.
     *
     * **What is accepted is not what is stored.** See fromImage(): anything longer than
     * STORED_MAX_EDGE_PX on its long side is scaled down before it goes anywhere near the
     * database. A 16 MB signature stored as-is would be 21 MB of base64 in the row, the
     * same again inside the receipt overlay, a receipt PDF per volunteer heavier than most
     * relays accept as an attachment, and a request that outgrows PHP's memory limit.
     */
    public const int MAX_FILE_SIZE = 16 * 1024 * 1024;

    /**
     * The long side of what gets stored, in pixels.
     *
     * The CERFA gives the signature a 14 mm box, so 1 000 px is over 1 800 dpi there —
     * far past anything a printer or a reader can use. Scaling to it turns a 16 MB scan
     * into tens of kilobytes.
     */
    public const int STORED_MAX_EDGE_PX = 1000;

    /**
     * The most pixels this will decode, as width × height.
     *
     * A guard on *dimensions*, which the byte cap does not give: PNG compresses flat
     * artwork enormously, so a 2 MB file can be 12 000 × 12 000 — and GD holds a decoded
     * image as 4 bytes per pixel, which is 576 MB before any scaling. Refusing here is a
     * message; discovering it in GD is a fatal error mid-request.
     *
     * 16 megapixels is a 4 000 × 4 000 scan, and costs 64 MB decoded.
     */
    public const int MAX_PIXELS = 16_000_000;

    /** Raster only. An SVG is markup, and markup has no business in a stamped PDF. */
    public const array ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::MIME_TYPE_MAX_LENGTH)]
    private string $mimeType;

    /** The image, base64-encoded. See the class docblock for why it is not `bytea`. */
    #[ORM\Column(type: Types::TEXT)]
    private string $base64;

    /**
     * What the file was called when it was uploaded. Kept so whoever comes back to the
     * page a year later can tell which scan they put there.
     */
    #[ORM\Column(length: self::ORIGINAL_FILENAME_MAX_LENGTH, nullable: true)]
    private ?string $originalFilename;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $uploadedAt;

    public function __construct(string $mimeType, string $base64, ?string $originalFilename = null)
    {
        $this->id = Uuid::v7();
        $this->mimeType = $mimeType;
        $this->base64 = $base64;
        $this->originalFilename = $originalFilename;
        $this->uploadedAt = new DateTimeImmutable();
    }

    /**
     * Builds a signature from raw image bytes, **scaled down to what a receipt can use**.
     *
     * Here rather than in a service because there is exactly one way a signature may come
     * into being, and a setter cannot reach a service — a second, optional step would be a
     * step somebody forgets, and the thing they would forget is the one that keeps a 16 MB
     * scan out of every receipt PDF and every email.
     *
     * An image already within STORED_MAX_EDGE_PX is kept **byte for byte**: re-encoding it
     * would cost quality and alpha for nothing.
     *
     * @throws SignatureImageException when the bytes are not a usable PNG or JPEG, or are
     *                                 too many pixels to decode safely
     */
    public static function fromImage(string $bytes, ?string $originalFilename = null): self
    {
        $size = getimagesizefromstring($bytes);

        if (false === $size) {
            throw SignatureImageException::notAnImage();
        }

        [$width, $height] = $size;
        $mimeType = $size['mime'];

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw SignatureImageException::unsupportedType($mimeType);
        }

        // Before decoding, not after: GD allocates 4 bytes per pixel the moment it reads
        // the file, so a check afterwards would come too late to prevent anything.
        if ($width * $height > self::MAX_PIXELS) {
            throw SignatureImageException::tooManyPixels($width, $height);
        }

        $longestEdge = max($width, $height);

        if ($longestEdge <= self::STORED_MAX_EDGE_PX) {
            return new self($mimeType, base64_encode($bytes), $originalFilename);
        }

        return new self(
            'image/png',
            base64_encode(self::scaleDown($bytes, $width, $height, $longestEdge)),
            $originalFilename,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getBase64(): string
    {
        return $this->base64;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function getUploadedAt(): DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    /**
     * The image as a `data:` URI — the only form anything consumes it in.
     *
     * Both the receipt overlay and the back-office preview embed it inline: Gotenberg
     * renders the overlay in a container that cannot reach this application, so a URL
     * would come back as a broken image on the finished PDF.
     */
    public function toDataUri(): string
    {
        return sprintf('data:%s;base64,%s', $this->mimeType, $this->base64);
    }

    /** Roughly the size of the original file, for display. Base64 carries 3 bytes per 4. */
    public function getApproximateByteSize(): int
    {
        return intdiv(strlen($this->base64) * 3, 4);
    }

    /**
     * Scales the image so its long side is STORED_MAX_EDGE_PX, and re-encodes it as PNG.
     *
     * PNG whatever came in: a signature is line art on a light background, which PNG stores
     * losslessly and small, and it is the only one of the two formats that keeps
     * transparency — a JPEG re-encode would paint the CERFA's own rules out behind a white
     * rectangle.
     *
     * @throws SignatureImageException when GD cannot decode or re-encode the image
     */
    private static function scaleDown(string $bytes, int $width, int $height, int $longestEdge): string
    {
        $source = imagecreatefromstring($bytes);

        if (false === $source) {
            throw SignatureImageException::notAnImage();
        }

        $ratio = self::STORED_MAX_EDGE_PX / $longestEdge;
        // At least one pixel each way: a 1 200 × 3 strip would otherwise scale to a height of
        // zero, which imagescale refuses.
        $scaled = imagescale(
            $source,
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
        );

        if (false === $scaled) {
            throw SignatureImageException::cannotResize();
        }

        // Keep the alpha channel through the encode; without these two, the transparent parts
        // of a scan come out black on the receipt.
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);

        ob_start();
        $written = imagepng($scaled);
        $png = (string) ob_get_clean();

        if (!$written || '' === $png) {
            throw SignatureImageException::cannotResize();
        }

        // No imagedestroy(): deprecated in PHP 8.5 and a no-op since 8.0 — a GD image is an
        // object and the garbage collector frees it.
        return $png;
    }
}
