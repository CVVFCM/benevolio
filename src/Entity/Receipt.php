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
 * A CERFA 2041-RD issued to a volunteer for one **civil year**.
 *
 * Not per declaration, which is what lot 7 did and what could not work: a volunteer files
 * several declarations a year, and the figure they carry to their income-tax return is the
 * year's total (CGI art. 200 applies to the *revenus* of a calendar year). Four numbered
 * documents for one year are unusable, and none of them could be issued before the year
 * ended anyway.
 *
 * **The civil year, not the exercice.** An association may run September to August; its
 * ledger follows that, this does not. The two periods never share a query — see
 * App\Accounting\LedgerBuilder for one and App\Receipt\YearlyReceiptRun for the other.
 *
 * **There is deliberately no unique key on (person, year).** Re-running a year issues a new
 * receipt with a new number and leaves the previous one standing, which is what a
 * rectificatif is; the object key carries the number so the earlier PDF survives too.
 *
 * **The amount is the abandon de frais only.** Donated hours are a contribution volontaire
 * en nature: off balance sheet, and they open no right to a deduction. A receipt that
 * included them would overstate what the volunteer may claim, which CGI art. 1740 A
 * penalises at 25% of the amounts wrongly stated.
 *
 * The volunteer's name and address are **snapshots as printed**. A volunteer who moves must
 * not retroactively alter a receipt already issued — the same argument that keeps a task
 * from being deleted once used.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: ReceiptRepository::class)]
#[ORM\Table(name: 'receipt')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_organization_number', columns: ['organization_id', 'number'])]
#[ORM\Index(name: 'idx_receipt_organization_year', columns: ['organization_id', 'year'])]
class Receipt implements TenantAware
{
    use TenantAwareTrait;

    /**
     * The number is a bare sequence now — `0001` — so this is generous rather than
     * derived. It used to be `FiscalYear::NAME_MAX_LENGTH + 5`, back when the number
     * carried the exercice's name, which stopped meaning anything once the receipt became
     * a civil year and the exercice need not be one.
     */
    public const int NUMBER_MAX_LENGTH = 20;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * The *numéro d'ordre du reçu*: `0001`, `0002`, … A single continuous series per
     * association, all years together, allocated by App\Receipt\ReceiptNumberAllocator
     * under a row lock so two runs cannot be handed the same one.
     */
    #[ORM\Column(length: self::NUMBER_MAX_LENGTH)]
    private string $number;

    /**
     * NO ACTION, not CASCADE: deleting a volunteer must not delete the tax receipts issued
     * to them — the database refuses instead. See App\Entity\DeclarationAction for why
     * NO ACTION and not RESTRICT (DBAL maps only 23503, and RESTRICT raises 23001).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'NO ACTION')]
    private Person $person;

    /** The civil year the receipt covers: 2025. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $year;

    /** The abandon de frais total for that year, in cents. Never includes donated hours. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCents;

    /** Where the PDF was written, e.g. `2025/cerfa-camille-berthier-0001.pdf`. */
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
        Person $person,
        int $year,
        string $number,
        int $amountCents,
        string $storagePath,
        string $volunteerName,
        string $volunteerAddress,
        DateTimeImmutable $issuedAt,
    ) {
        $this->id = Uuid::v7();
        // Person is TenantAware, so the tenant comes from them rather than being passed
        // again and risking disagreement.
        $this->organization = $person->getOrganization();
        $this->person = $person;
        $this->year = $year;
        $this->number = $number;
        $this->amountCents = $amountCents;
        $this->storagePath = $storagePath;
        $this->volunteerName = $volunteerName;
        $this->volunteerAddress = $volunteerAddress;
        $this->issuedAt = $issuedAt;
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

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function getYear(): int
    {
        return $this->year;
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
