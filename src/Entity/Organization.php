<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\SignatureImageException;
use App\Repository\OrganizationRepository;
use App\ValueObject\Address;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function file_get_contents;
use function mb_trim;

/**
 * The tenant: one association loi 1901.
 *
 * Every business entity belongs to exactly one Organization through
 * App\Tenant\TenantAwareTrait, and App\Doctrine\Filter\OrganizationFilter keeps
 * queries inside the current one. Organization itself is NOT tenant-aware — it
 * is the tenant.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[UniqueEntity(fields: ['slug'], message: 'Ce raccourci est déjà utilisé par une autre association.')]
class Organization
{
    public const int NAME_MAX_LENGTH = 150;
    public const int SLUG_MAX_LENGTH = 100;

    /** SIREN is 9 digits, an RNA number is `W` + 9 digits. */
    public const int SIREN_OR_RNA_MAX_LENGTH = 20;
    public const int OBJET_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /**
     * Used in the public volunteer URLs (/a/{slug}/…), so it must stay stable
     * once communicated and must not collide with another organization.
     */
    #[ORM\Column(length: self::SLUG_MAX_LENGTH, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::SLUG_MAX_LENGTH)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'Le raccourci ne peut contenir que des minuscules, des chiffres et des tirets.',
    )]
    private string $slug = '';

    /**
     * An inactive organization keeps its data but is refused at tenant
     * resolution, so both its admin backoffice and its public forms are closed.
     */
    #[ORM\Column]
    private bool $active = true;

    /*
     * ---------------------------------------------------------------- CERFA ----
     *
     * What form 2041-RD asks about the beneficiary organisation, and none of which
     * the application needed until it started issuing receipts.
     *
     * All nullable, because every association that already exists predates these
     * columns. A receipt is refused rather than issued incomplete — see
     * App\Receipt\ReceiptEligibility — so "absent" never means "printed blank".
     */

    /**
     * SIREN (9 digits) or RNA (W + 9 digits). **Without it a receipt is not a valid
     * document**, which is why issuance refuses when it is missing rather than
     * leaving the line empty. Mandatory on the form since revision *05.
     */
    #[ORM\Column(length: self::SIREN_OR_RNA_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::SIREN_OR_RNA_MAX_LENGTH)]
    #[Assert\Regex(
        pattern: '/^(\d{9}|W\d{9})$/',
        message: 'Indiquez un SIREN (9 chiffres) ou un numéro RNA (W suivi de 9 chiffres).',
    )]
    private ?string $sirenOrRna = null;

    /*
     * The postal address, as loose columns rather than an #[ORM\Embedded] of
     * App\ValueObject\Address.
     *
     * NOT a preference: Doctrine cannot express a genuinely nullable embeddable. An
     * organisation without an address would hydrate all-null columns into Address's
     * non-nullable typed properties and raise a TypeError. So the parts are stored
     * here and getPostalAddress() builds the value object when they are all present —
     * which keeps Address as the single place that knows how an address validates and
     * how it reads.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $addressNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressStreet = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $addressPostcode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressCity = null;

    /** ISO 3166-1 alpha-2, like Address::$country. */
    #[ORM\Column(length: 2, nullable: true, options: ['fixed' => true])]
    private ?string $addressCountry = null;

    /** The *objet* of the association, as declared. Printed verbatim on the form. */
    #[ORM\Column(length: self::OBJET_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::OBJET_MAX_LENGTH)]
    private ?string $objet = null;

    /**
     * The signature stamped onto every reçu fiscal, or null while there is none.
     *
     * **The owning side, deliberately.** This row is hydrated on every request by
     * App\Tenant\TenantRequestListener, and an owning to-one reads only `signature_id` —
     * the image loads lazily, when a receipt or the back-office preview asks for it. See
     * App\Entity\OrganizationSignature for the rest of the reasoning.
     *
     * `orphanRemoval` so replacing a signature does not leave the old one behind.
     */
    #[ORM\OneToOne(cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationSignature $signature = null;

    /*
     * There is deliberately NO $signatureUpload property.
     *
     * `signatureUpload` is a virtual property — getter and setter only, which is all
     * Symfony's PropertyAccess needs to bind a form field. **An UploadedFile must never be
     * held on this entity**: Organization is reachable from the security token through
     * User, and Symfony's ContextListener serializes that token into the session on every
     * response. An UploadedFile in the graph makes that throw ("Serialization of
     * 'UploadedFile' is not allowed") and every response after the upload is a 500.
     *
     * The file is therefore converted on the way in and dropped, and what gets validated
     * is the stored signature — see validateSignature() below.
     */

    /**
     * Why the last uploaded signature was refused, in French, or null. Not a column.
     *
     * A string and not the exception, let alone the file: this entity is reachable from the
     * security token, and ContextListener serialises that token into the session on every
     * response — see the comment where $signatureUpload would have been.
     */
    private ?string $signatureUploadError = null;

    /**
     * The « supprimer la signature » checkbox. Not a column either.
     *
     * Without it a signature could be replaced but never removed: an empty file input
     * means "keep what is there", which is the only sane reading of an edit form.
     */
    private bool $signatureCleared = false;

    /**
     * How many reçus fiscaux this association has issued, and therefore what the next
     * number is. Only App\Receipt\ReceiptNumberAllocator moves it, under a row lock.
     *
     * On the association and not on a period: the number is one continuous series,
     * `0001`, `0002`, …, whatever year the receipt covers. It used to sit on FiscalYear,
     * which stopped making sense when the receipt became a civil year — an exercice
     * running September to August cannot number a January-to-December document.
     *
     * A counter rather than `MAX(number) + 1`: the numbering must be continuous and never
     * reused, so it has to survive a receipt being deleted. Counting rows would silently
     * hand out a number that had already been issued.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $lastReceiptSequence = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * The constructor takes no argument because EasyAdmin instantiates entities
     * with `new $fqcn()` before binding the form. Required fields are guarded by
     * the validation constraints above, not by the constructor signature.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return '' !== $this->name ? $this->name : $this->slug;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getSirenOrRna(): ?string
    {
        return $this->sirenOrRna;
    }

    public function setSirenOrRna(?string $sirenOrRna): self
    {
        $sirenOrRna = null === $sirenOrRna ? null : mb_trim($sirenOrRna);
        $this->sirenOrRna = '' === $sirenOrRna ? null : $sirenOrRna;

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): self
    {
        $this->objet = $objet;

        return $this;
    }

    /**
     * The address as a value object, or null while it is incomplete.
     *
     * Built on demand rather than mapped, for the reason recorded on the columns
     * above. Returns null rather than a half-filled Address: the CERFA either carries
     * a real address or the receipt is not issued.
     */
    public function getPostalAddress(): ?Address
    {
        if (null === $this->addressStreet || null === $this->addressPostcode
            || null === $this->addressCity || null === $this->addressCountry) {
            return null;
        }

        return new Address(
            $this->addressNumber,
            $this->addressStreet,
            $this->addressPostcode,
            $this->addressCity,
            $this->addressCountry,
        );
    }

    public function setPostalAddress(?Address $address): self
    {
        $this->addressNumber = $address?->number;
        $this->addressStreet = $address?->street;
        $this->addressPostcode = $address?->postcode;
        $this->addressCity = $address?->city;
        $this->addressCountry = $address?->country;

        return $this;
    }

    public function getAddressNumber(): ?string
    {
        return $this->addressNumber;
    }

    public function setAddressNumber(?string $addressNumber): self
    {
        $this->addressNumber = $addressNumber;

        return $this;
    }

    public function getAddressStreet(): ?string
    {
        return $this->addressStreet;
    }

    public function setAddressStreet(?string $addressStreet): self
    {
        $this->addressStreet = $addressStreet;

        return $this;
    }

    public function getAddressPostcode(): ?string
    {
        return $this->addressPostcode;
    }

    public function setAddressPostcode(?string $addressPostcode): self
    {
        $this->addressPostcode = $addressPostcode;

        return $this;
    }

    public function getAddressCity(): ?string
    {
        return $this->addressCity;
    }

    public function setAddressCity(?string $addressCity): self
    {
        $this->addressCity = $addressCity;

        return $this;
    }

    public function getAddressCountry(): ?string
    {
        return $this->addressCountry;
    }

    public function setAddressCountry(?string $addressCountry): self
    {
        $this->addressCountry = $addressCountry;

        return $this;
    }

    public function getSignature(): ?OrganizationSignature
    {
        return $this->signature;
    }

    public function setSignature(?OrganizationSignature $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    /**
     * Always null: a file input on an edit form starts empty, and re-offering the stored
     * signature would mean re-uploading it on every save.
     */
    public function getSignatureUpload(): ?UploadedFile
    {
        return null;
    }

    /**
     * Turns an uploaded image into the stored signature, replacing any previous one.
     *
     * A null argument is what an untouched file input submits, and it means "leave the
     * signature alone" — not "remove it". Removal is $signatureCleared.
     *
     * The UploadedFile is read and forgotten here, never stored on the entity — see the
     * comment where the property would have been. What is validated is the result, by
     * validateSignature(), so an oversized file or something that is not an image is still
     * refused; nothing is flushed when validation fails.
     */
    public function setSignatureUpload(?UploadedFile $file): self
    {
        if (null === $file) {
            return $this;
        }

        // Read, converted, and forgotten. The bytes go through
        // OrganizationSignature::fromImage(), which scales anything longer than 1 000 px on
        // its long side down before it is stored — a 16 MB scan is accepted from the browser
        // and lands in the database as tens of kilobytes.
        //
        // The refusal is caught rather than thrown on: this runs while the form is binding,
        // so an exception here would be a 500 on a wrong file. It becomes a violation on the
        // file field instead, in validateSignature() below.
        try {
            $this->signature = OrganizationSignature::fromImage(
                (string) file_get_contents($file->getPathname()),
                $file->getClientOriginalName(),
            );
            $this->signatureUploadError = null;
        } catch (SignatureImageException $e) {
            $this->signatureUploadError = $e->userMessage;
        }

        return $this;
    }

    public function isSignatureCleared(): bool
    {
        return $this->signatureCleared;
    }

    /**
     * Ticking the box drops the signature; orphanRemoval deletes the row on flush.
     */
    public function setSignatureCleared(bool $cleared): self
    {
        $this->signatureCleared = $cleared;

        if ($cleared) {
            $this->signature = null;
        }

        return $this;
    }

    /**
     * Reports an upload the conversion refused.
     *
     * The refusal happens while the form is binding, in setSignatureUpload(), where throwing
     * would be a 500 over a wrong file. The reason is kept as a string and turned into a
     * violation here, on the file field the person just used.
     *
     * There is nothing left to validate about the stored image itself: it only exists if
     * OrganizationSignature::fromImage() decoded it, found a type this application stamps, and
     * scaled it to something a receipt can carry. Checking that again would be checking the
     * constructor.
     */
    #[Assert\Callback]
    public function validateSignature(ExecutionContextInterface $context): void
    {
        if (null === $this->signatureUploadError) {
            return;
        }

        $context->buildViolation($this->signatureUploadError)
            ->atPath('signatureUpload')
            ->addViolation();
    }

    /**
     * The signature as a `data:` URI, or null when there is none.
     *
     * The one place that string is built, so the receipt overlay and the back-office
     * preview cannot disagree about it.
     */
    public function getSignatureDataUri(): ?string
    {
        return $this->signature?->toDataUri();
    }

    public function getLastReceiptSequence(): int
    {
        return $this->lastReceiptSequence;
    }

    /**
     * Takes the next number in this association's receipt series.
     *
     * Deliberately NOT general-purpose API — call it only from
     * App\Receipt\ReceiptNumberAllocator, which holds the row lock that makes it safe.
     * Called without that lock, two concurrent runs both read the same value.
     */
    public function takeNextReceiptSequence(): int
    {
        return ++$this->lastReceiptSequence;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
