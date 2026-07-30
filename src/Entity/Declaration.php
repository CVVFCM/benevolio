<?php

declare(strict_types=1);

namespace App\Entity;

use App\Declaration\ConfirmationToken;
use App\Repository\DeclarationRepository;
use App\State\DeclarationActionState;
use App\State\DeclarationState;
use App\Tenant\TenantAware;
use App\Tenant\TenantAwareTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function sprintf;

/**
 * One submission of the public form: a person, a set of contributed actions, and
 * the two legal statements that make it usable.
 *
 * Both statements are mandatory, so every declaration carries the waiver. That
 * matters legally: waiving reimbursement of expenses (*abandon de frais*) is what
 * turns those expenses into a donation eligible for a CERFA receipt (754x).
 * Donated hours, by contrast, are never receiptable (864/875, off balance sheet).
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity(repositoryClass: DeclarationRepository::class)]
#[ORM\Table(name: 'declaration')]
class Declaration implements TenantAware
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'declarations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\Column(enumType: DeclarationState::class)]
    private DeclarationState $state = DeclarationState::AWAITING_CONFIRMATION;

    /**
     * @var Collection<int, DeclarationAction>
     */
    #[ORM\OneToMany(
        targetEntity: DeclarationAction::class,
        mappedBy: 'declaration',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $actions;

    /** "J'atteste que les informations saisies sont exactes et sincères." */
    #[ORM\Column]
    private bool $accuracyAttested;

    /** "Je confirme renoncer au remboursement des frais engagés détaillés ci-avant." */
    #[ORM\Column]
    private bool $expensesWaived;

    /*
     * A declaration has NO link to a receipt, and no reason for not having one.
     *
     * It used to own both: lot 7 issued a CERFA the moment a declaration was validated. A
     * receipt is a civil year of one volunteer's waived expenses — several declarations,
     * possibly none of them decisive on their own — so a per-declaration relation could
     * only ever mislead. See App\Entity\Receipt and App\Receipt\YearlyReceiptRun.
     */

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $submittedAt;

    /**
     * The secret in the confirmation link.
     *
     * Kept after use rather than cleared. It can only ever cause one confirmation
     * — `confirm` has AWAITING_CONFIRMATION as its only source state, and
     * confirmedAt is the record — so retaining it is harmless, and it is what lets
     * a second click (or a mail client prefetching the link) land on a success
     * page instead of a 404.
     */
    #[ORM\Column(length: ConfirmationToken::MAX_LENGTH, unique: true, nullable: true)]
    private ?string $confirmationToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmationTokenExpiresAt = null;

    /**
     * When the volunteer proved the address works. Kept after the token is
     * cleared: a tax receipt has to be able to show the donation was confirmed,
     * and when.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    public function __construct(
        Organization $organization,
        Person $person,
        bool $accuracyAttested,
        bool $expensesWaived,
    ) {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->person = $person;
        $this->accuracyAttested = $accuracyAttested;
        $this->expensesWaived = $expensesWaived;
        $this->actions = new ArrayCollection();
        $this->submittedAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s — %s', $this->person->getFullName(), $this->submittedAt->format('d/m/Y'));
    }

    /**
     * Arms the confirmation link. Called once, by DeclarationSubmitter, before the
     * email goes out.
     */
    public function issueConfirmationToken(ConfirmationToken $token, DateTimeImmutable $expiresAt): void
    {
        $this->confirmationToken = $token->value;
        $this->confirmationTokenExpiresAt = $expiresAt;
    }

    public function getConfirmationToken(): ?ConfirmationToken
    {
        return null === $this->confirmationToken ? null : new ConfirmationToken($this->confirmationToken);
    }

    public function getConfirmationTokenExpiresAt(): ?DateTimeImmutable
    {
        return $this->confirmationTokenExpiresAt;
    }

    public function isConfirmationTokenExpired(DateTimeImmutable $now): bool
    {
        return null === $this->confirmationTokenExpiresAt || $this->confirmationTokenExpiresAt < $now;
    }

    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    /**
     * Records the confirmation. The state transition itself is the state machine's
     * job — see App\Declaration\DeclarationConfirmer.
     *
     * The expiry is dropped because it no longer means anything, but the token
     * stays so the link keeps resolving to a friendly page.
     */
    public function markConfirmed(DateTimeImmutable $now): void
    {
        $this->confirmedAt = $now;
        $this->confirmationTokenExpiresAt = null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    /**
     * There is deliberately no setState(). Finite writes $state through
     * reflection, so a setter would only exist as a way to bypass the state
     * machine and its guard — go through Finite\StateMachine::apply().
     */
    public function getState(): DeclarationState
    {
        return $this->state;
    }

    /**
     * @return Collection<int, DeclarationAction>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(DeclarationAction $action): void
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
        }
    }

    public function removeAction(DeclarationAction $action): void
    {
        $this->actions->removeElement($action);
    }

    public function isAccuracyAttested(): bool
    {
        return $this->accuracyAttested;
    }

    /**
     * Named isExpensesWaived(), not the grammatical areExpensesWaived(), because
     * Symfony's PropertyAccess only recognises get/is/has. Under the old name
     * EasyAdmin could not read the property at all and rendered the field as
     * "AUCUN(E)" whatever its value — on the waiver, which is the thing that makes
     * a tax receipt legally possible. Grammar is not worth that.
     */
    public function isExpensesWaived(): bool
    {
        return $this->expensesWaived;
    }

    public function getSubmittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /**
     * Read by App\State\Listener\DeclarationTransitionGuard: the whole-declaration
     * verdict is only allowed once every line agrees with it.
     */
    public function hasEveryActionInState(DeclarationActionState $state): bool
    {
        if ($this->actions->isEmpty()) {
            return false;
        }

        foreach ($this->actions as $action) {
            if ($state !== $action->getState()) {
                return false;
            }
        }

        return true;
    }

    public function hasUndecidedAction(): bool
    {
        foreach ($this->actions as $action) {
            if (!$action->getState()->isDecided()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total hours declared across every line, decided or not, as a DECIMAL(x,2)
     * string.
     *
     * Summed in integer hundredths rather than as floats, so the figure stays
     * exact once valuation multiplies it by an hourly rate. ext-bcmath is not
     * installed and is not worth requiring for this.
     */
    public function getTotalWorkHours(): string
    {
        $hundredths = 0;
        foreach ($this->actions as $action) {
            $hundredths += $action->getWorkHoursInHundredths();
        }

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    /** Total kilometres: each line is one-way distance × number of journeys. */
    public function getTotalDistanceKm(): int
    {
        $total = 0;
        foreach ($this->actions as $action) {
            $total += $action->getTotalDistanceKm();
        }

        return $total;
    }
}
