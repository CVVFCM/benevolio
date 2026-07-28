<?php

declare(strict_types=1);

namespace App\Form\Declaration;

use App\Entity\DeclarationAction;
use App\Entity\EventType;
use App\Enum\FiscalPower;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One row of the "actions" step: a scalar mirror of App\Entity\DeclarationAction.
 *
 * Every constraint is in the `actions` group, never in `Default`. The flow
 * validates with groups ['Default', <current step>], so a constraint in Default
 * would fire on the person step too — where these fields are legitimately empty.
 * This object is only reached through DeclarationDraft::$actions, whose
 * Assert\Valid also carries the `actions` group, so the cascade validates in that
 * same group.
 */
final class ActionDraft
{
    public const string GROUP = 'actions';
    private const int MAX_CONSECUTIVE_DAYS = 60;
    private const int MAX_DISTANCE_KM = 2000;
    private const int MAX_JOURNEYS = 200;
    private const string MAX_WORK_HOURS = '999.99';

    /**
     * The entity, not an enum: each association manages its own list. The form's
     * choices are already restricted to the current tenant by OrganizationFilter,
     * so a draft cannot carry another association's type.
     */
    #[Assert\NotNull(message: 'Choisissez un type d\'événement.', groups: [self::GROUP])]
    public ?EventType $eventType = null;

    #[Assert\NotBlank(message: 'Indiquez l\'intitulé de l\'événement.', groups: [self::GROUP])]
    #[Assert\Length(max: DeclarationAction::TITLE_MAX_LENGTH, groups: [self::GROUP])]
    public ?string $title = null;

    #[Assert\Length(max: 2000, groups: [self::GROUP])]
    public ?string $description = null;

    #[Assert\NotNull(message: 'Indiquez la date.', groups: [self::GROUP])]
    #[Assert\LessThanOrEqual(
        value: 'today',
        message: 'Une action ne peut pas être déclarée à l\'avance.',
        groups: [self::GROUP],
    )]
    public ?DateTimeImmutable $date = null;

    #[Assert\Positive(message: 'Le nombre de jours doit être au moins de 1.', groups: [self::GROUP])]
    #[Assert\LessThanOrEqual(self::MAX_CONSECUTIVE_DAYS, groups: [self::GROUP])]
    public int $consecutiveDays = 1;

    /** Number of ONE-WAY journeys. A return trip is two. */
    #[Assert\GreaterThanOrEqual(0, groups: [self::GROUP])]
    #[Assert\LessThanOrEqual(self::MAX_JOURNEYS, groups: [self::GROUP])]
    public int $journeys = 0;

    /** Kilometres of ONE journey, ONE WAY. */
    #[Assert\GreaterThanOrEqual(0, groups: [self::GROUP])]
    #[Assert\LessThanOrEqual(self::MAX_DISTANCE_KM, groups: [self::GROUP])]
    public int $distanceKm = 0;

    public bool $ownVehicle = false;

    #[Assert\Expression(
        'this.ownVehicle == false or this.fiscalPower !== null',
        message: 'Indiquez la puissance fiscale du véhicule.',
        groups: [self::GROUP],
    )]
    public ?FiscalPower $fiscalPower = null;

    /**
     * Kept as a decimal string rather than a float: NumberType with
     * `input: 'string'` transforms it, and the entity column is DECIMAL(5,2), so
     * the exact value the volunteer typed reaches the database.
     */
    #[Assert\NotBlank(message: 'Indiquez le nombre d\'heures.', groups: [self::GROUP])]
    #[Assert\Positive(message: 'Le nombre d\'heures doit être supérieur à zéro.', groups: [self::GROUP])]
    #[Assert\LessThanOrEqual(self::MAX_WORK_HOURS, groups: [self::GROUP])]
    public ?string $workHours = null;

    /**
     * Declaring travel without waiving its reimbursement makes no sense here:
     * both legal statements are mandatory, so any kilometre entered is a donation.
     * What this catches is the opposite mistake — kilometres with no journeys, or
     * journeys with no distance — which would silently value at zero.
     */
    #[Assert\Callback(groups: [self::GROUP])]
    public function validateTravelIsCoherent(ExecutionContextInterface $context): void
    {
        if ($this->distanceKm > 0 && 0 === $this->journeys) {
            $context->buildViolation('Indiquez le nombre de trajets effectués.')
                ->atPath('journeys')
                ->addViolation();
        }

        if ($this->journeys > 0 && 0 === $this->distanceKm) {
            $context->buildViolation('Indiquez la distance parcourue.')
                ->atPath('distanceKm')
                ->addViolation();
        }
    }
}
