<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function intdiv;
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
     * **What is accepted is not what is stored.** App\Organization\SignatureFactory scales
     * anything larger than a receipt can use before building one of these — see it for why,
     * and for the pixel ceiling that the byte cap does not cover.
     */
    public const int MAX_FILE_SIZE = 16 * 1024 * 1024;

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
}
