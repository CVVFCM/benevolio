<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReceiptRepository;
use App\Tenant\TenantAware;
use App\Tenant\TenantAwareTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A CERFA 2041-RD issued to a volunteer.
 *
 * **This row is the record, not the PDF.** The object in S3 can be overwritten — the
 * key is `<year>/cerfa-firstname-lastname.pdf`, so a volunteer receipted twice in one
 * year replaces their earlier file — but the number, the amount, the date and the
 * identity as printed all survive here. That is what makes "continuous per financial
 * year and never reused" checkable after the fact.
 *
 * **The amount is the abandon de frais only.** Donated hours are a contribution
 * volontaire en nature: off balance sheet, and they open no right to a deduction. A
 * receipt that included them would overstate what the volunteer may claim, which
 * CGI art. 1740 A penalises at 25% of the amounts wrongly stated.
 *
 * The volunteer's name and address are **snapshots as printed**. A volunteer who moves
 * must not retroactively alter a receipt already issued — the same argument that keeps
 * a task from being deleted once used, and froze the rate on a filed line before it.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: ReceiptRepository::class)]
#[ORM\Table(name: 'receipt')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_organization_number', columns: ['organization_id', 'number'])]
class Receipt implements TenantAware
{
    use TenantAwareTrait;

    /**
     * Wide enough for the longest number the allocator can build: an exercice name of
     * FiscalYear::NAME_MAX_LENGTH characters plus `-` and a four-digit sequence. An
     * association calling its exercice "2025-2026" already produces `2025-2026-0001`,
     * so 20 would have silently truncated a document number.
     */
    public const int NUMBER_MAX_LENGTH = FiscalYear::NAME_MAX_LENGTH + 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * The *numéro d'ordre du reçu*, `2026-0001`. Unique per association, allocated by
     * App\Receipt\ReceiptNumberAllocator under a lock so two treasurers validating at
     * the same moment cannot be handed the same one.
     */
    #[ORM\Column(length: self::NUMBER_MAX_LENGTH)]
    private string $number;

    /** One receipt per declaration; a declaration is receipted once and only once. */
    #[ORM\OneToOne(targetEntity: Declaration::class, inversedBy: 'receipt')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Declaration $declaration;

    /**
     * NO ACTION, not CASCADE: deleting an exercice must never delete the receipts
     * issued under it. See App\Entity\DeclarationAction for why NO ACTION and not
     * RESTRICT.
     */
    #[ORM\ManyToOne(targetEntity: FiscalYear::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'NO ACTION')]
    private FiscalYear $fiscalYear;

    /** The abandon de frais total, in cents. Never includes donated hours. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCents;

    /** Where the PDF was written, e.g. `2026/cerfa-camille-berthier.pdf`. */
    #[ORM\Column(length: 255)]
    private string $storagePath;

    /** The volunteer's name as printed on this receipt. */
    #[ORM\Column(length: 255)]
    private string $volunteerName;

    /** Their address as printed, on one line. */
    #[ORM\Column(length: 500)]
    private string $volunteerAddress;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    public function __construct(
        Declaration $declaration,
        FiscalYear $fiscalYear,
        string $number,
        int $amountCents,
        string $storagePath,
        string $volunteerName,
        string $volunteerAddress,
        DateTimeImmutable $issuedAt,
    ) {
        $this->id = Uuid::v7();
        $this->organization = $declaration->getOrganization();
        $this->declaration = $declaration;
        $this->fiscalYear = $fiscalYear;
        $this->number = $number;
        $this->amountCents = $amountCents;
        $this->storagePath = $storagePath;
        $this->volunteerName = $volunteerName;
        $this->volunteerAddress = $volunteerAddress;
        $this->issuedAt = $issuedAt;

        $declaration->attachReceipt($this);
    }

    public function __toString(): string
    {
        return $this->number;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getDeclaration(): Declaration
    {
        return $this->declaration;
    }

    public function getFiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getVolunteerName(): string
    {
        return $this->volunteerName;
    }

    public function getVolunteerAddress(): string
    {
        return $this->volunteerAddress;
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
