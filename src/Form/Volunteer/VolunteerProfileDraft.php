<?php

declare(strict_types=1);

namespace App\Form\Volunteer;

use App\Entity\Person;
use App\Enum\FiscalPower;
use App\Form\Declaration\ActionDraft;
use App\ValueObject\Address;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Mon profil »: the vehicle and distance every time, and the identity once — when a
 * first login creates the Person. Afterwards the identity changes only through a confirmed
 * declaration, so the IDENTITY group is validated on creation alone.
 *
 * The identity rules are the declaration form's (App\Form\Declaration\DeclarationDraft):
 * both end in the same App\ValueObject\Address.
 */
final class VolunteerProfileDraft
{
    public const string IDENTITY = 'identity';

    #[Assert\NotBlank(message: 'Indiquez votre prénom.', groups: [self::IDENTITY])]
    #[Assert\Length(max: Person::NAME_MAX_LENGTH, groups: [self::IDENTITY])]
    public ?string $firstName = null;

    #[Assert\NotBlank(message: 'Indiquez votre nom.', groups: [self::IDENTITY])]
    #[Assert\Length(max: Person::NAME_MAX_LENGTH, groups: [self::IDENTITY])]
    public ?string $lastName = null;

    #[Assert\Length(max: Address::NUMBER_MAX_LENGTH, groups: [self::IDENTITY])]
    public ?string $addressNumber = null;

    #[Assert\NotBlank(message: 'Indiquez votre rue ou votre lieu-dit.', groups: [self::IDENTITY])]
    #[Assert\Length(max: Address::STREET_MAX_LENGTH, groups: [self::IDENTITY])]
    public ?string $addressStreet = null;

    #[Assert\NotBlank(message: 'Indiquez votre code postal.', groups: [self::IDENTITY])]
    #[Assert\Length(max: Address::POSTCODE_MAX_LENGTH, groups: [self::IDENTITY])]
    #[Assert\When(
        expression: 'this.addressCountry === "'.Address::FRANCE.'"',
        constraints: [new Assert\Regex(
            pattern: Address::FRENCH_POSTCODE_PATTERN,
            message: 'Un code postal français comporte 5 chiffres.',
        )],
        groups: [self::IDENTITY],
    )]
    public ?string $addressPostcode = null;

    #[Assert\NotBlank(message: 'Indiquez votre commune.', groups: [self::IDENTITY])]
    #[Assert\Length(max: Address::CITY_MAX_LENGTH, groups: [self::IDENTITY])]
    public ?string $addressCity = null;

    #[Assert\NotBlank(message: 'Indiquez votre pays.', groups: [self::IDENTITY])]
    #[Assert\Country(message: 'Ce pays n\'est pas reconnu.', groups: [self::IDENTITY])]
    public ?string $addressCountry = 'FR';

    #[Assert\GreaterThanOrEqual(0)]
    #[Assert\LessThanOrEqual(ActionDraft::MAX_DISTANCE_KM)]
    public ?int $distanceKm = null;

    public ?FiscalPower $fiscalPower = null;

    public bool $electricVehicle = false;

    public static function of(Person $person): self
    {
        $draft = new self();
        $draft->distanceKm = $person->getDistanceKm();
        $draft->fiscalPower = $person->getFiscalPower();
        $draft->electricVehicle = $person->isElectricVehicle();

        return $draft;
    }

    public function address(): Address
    {
        return new Address(
            '' === $this->addressNumber ? null : $this->addressNumber,
            (string) $this->addressStreet,
            (string) $this->addressPostcode,
            (string) $this->addressCity,
            (string) $this->addressCountry,
        );
    }
}
