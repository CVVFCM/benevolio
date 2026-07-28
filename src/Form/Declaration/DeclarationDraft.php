<?php

declare(strict_types=1);

namespace App\Form\Declaration;

use App\Entity\Person;
use App\ValueObject\Address;
use App\ValueObject\Email;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the multi-step form binds to. A scalar mirror of the entities it will
 * eventually produce, kept mutable and constraint-driven so a volunteer sees
 * field-level errors instead of an exception.
 *
 * The value objects are built from it once, at the end, by
 * App\Declaration\DeclarationSubmitter — where their own assertions act as a
 * last-line invariant check rather than as the error-reporting mechanism.
 *
 * VALIDATION GROUPS ARE LOAD-BEARING. Symfony's FormFlowType validates with
 * `['Default', <current step>]`, so any constraint left in the Default group
 * fires on every step — and step 1 would fail on the still-empty step 2 fields.
 * Every constraint below therefore names its step, and nothing is in Default.
 */
final class DeclarationDraft
{
    public const string STEP_PERSON = 'person';
    public const string STEP_ACTIONS = 'actions';
    public const string STEP_LEGAL = 'legal';

    /**
     * Which step the volunteer is on. Required by FormFlowType's
     * `step_property_path`, which reads and writes it as the flow advances.
     */
    public ?string $step = null;

    #[Assert\NotBlank(message: 'Indiquez votre prénom.', groups: [self::STEP_PERSON])]
    #[Assert\Length(max: Person::NAME_MAX_LENGTH, groups: [self::STEP_PERSON])]
    public ?string $firstName = null;

    #[Assert\NotBlank(message: 'Indiquez votre nom.', groups: [self::STEP_PERSON])]
    #[Assert\Length(max: Person::NAME_MAX_LENGTH, groups: [self::STEP_PERSON])]
    public ?string $lastName = null;

    #[Assert\NotBlank(message: 'Indiquez votre adresse électronique.', groups: [self::STEP_PERSON])]
    #[Assert\Email(message: 'Cette adresse électronique n\'est pas valide.', groups: [self::STEP_PERSON])]
    #[Assert\Length(max: Email::MAX_LENGTH, groups: [self::STEP_PERSON])]
    public ?string $email = null;

    #[Assert\Length(max: Address::NUMBER_MAX_LENGTH, groups: [self::STEP_PERSON])]
    public ?string $addressNumber = null;

    #[Assert\NotBlank(message: 'Indiquez votre rue ou votre lieu-dit.', groups: [self::STEP_PERSON])]
    #[Assert\Length(max: Address::STREET_MAX_LENGTH, groups: [self::STEP_PERSON])]
    public ?string $addressStreet = null;

    /**
     * The 5-digit rule only applies to France; Address enforces the same thing on
     * the way in, but a form has to say which field is wrong.
     */
    #[Assert\NotBlank(message: 'Indiquez votre code postal.', groups: [self::STEP_PERSON])]
    #[Assert\Length(max: Address::POSTCODE_MAX_LENGTH, groups: [self::STEP_PERSON])]
    #[Assert\When(
        expression: 'this.addressCountry === "FR"',
        constraints: [new Assert\Regex(
            pattern: '/^\d{5}$/',
            message: 'Un code postal français comporte 5 chiffres.',
        )],
        groups: [self::STEP_PERSON],
    )]
    public ?string $addressPostcode = null;

    #[Assert\NotBlank(message: 'Indiquez votre commune.', groups: [self::STEP_PERSON])]
    #[Assert\Length(max: Address::CITY_MAX_LENGTH, groups: [self::STEP_PERSON])]
    public ?string $addressCity = null;

    #[Assert\NotBlank(message: 'Indiquez votre pays.', groups: [self::STEP_PERSON])]
    #[Assert\Country(message: 'Ce pays n\'est pas reconnu.', groups: [self::STEP_PERSON])]
    public ?string $addressCountry = 'FR';

    /**
     * @var list<ActionDraft>
     */
    #[Assert\Count(
        min: 1,
        minMessage: 'Ajoutez au moins une action bénévole.',
        groups: [self::STEP_ACTIONS],
    )]
    #[Assert\Valid(groups: [self::STEP_ACTIONS])]
    public array $actions;

    #[Assert\IsTrue(
        message: 'Vous devez attester de l\'exactitude des informations saisies.',
        groups: [self::STEP_LEGAL],
    )]
    public bool $accuracyAttested = false;

    /**
     * Mandatory: waiving reimbursement is what turns the declared expenses into a
     * donation eligible for a tax receipt. A declaration without it would capture
     * expenses that legally are not a donation at all.
     */
    #[Assert\IsTrue(
        message: 'Vous devez confirmer renoncer au remboursement de vos frais.',
        groups: [self::STEP_LEGAL],
    )]
    public bool $expensesWaived = false;

    /**
     * Starts with one blank action so the actions step renders a usable row
     * server-side. Without it a volunteer with JavaScript disabled reaches a step
     * with no fields at all and no way forward.
     */
    public function __construct()
    {
        $this->actions = [new ActionDraft()];
    }
}
