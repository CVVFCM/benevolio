<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Exception\InvalidValueObjectException;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Intl\Countries;

use function sprintf;

/**
 * A postal address, good enough to print on a CERFA 11580*05 receipt.
 *
 * Stored as columns on the owning table (address_street, address_city, …), not as
 * a separate row. Doctrine hydrates embeddables through reflection WITHOUT
 * calling the constructor, so these invariants guard writes only — which is the
 * point: the data was validated on the way in.
 *
 * `number` is optional on purpose. Plenty of French addresses are a lieu-dit with
 * no street number, and refusing them would lock those volunteers out.
 */
#[ORM\Embeddable]
final readonly class Address implements Stringable
{
    public const int NUMBER_MAX_LENGTH = 20;
    public const int STREET_MAX_LENGTH = 200;
    public const int POSTCODE_MAX_LENGTH = 16;
    public const int CITY_MAX_LENGTH = 120;

    private const string FRANCE = 'FR';

    #[ORM\Column(length: self::NUMBER_MAX_LENGTH, nullable: true)]
    public ?string $number;

    #[ORM\Column(length: self::STREET_MAX_LENGTH)]
    public string $street;

    #[ORM\Column(length: self::POSTCODE_MAX_LENGTH)]
    public string $postcode;

    #[ORM\Column(length: self::CITY_MAX_LENGTH)]
    public string $city;

    /** ISO 3166-1 alpha-2. */
    #[ORM\Column(length: 2, options: ['fixed' => true])]
    public string $country;

    public function __construct(
        ?string $number,
        string $street,
        string $postcode,
        string $city,
        string $country,
    ) {
        $number = mb_trim((string) $number);
        $street = mb_trim($street);
        $postcode = mb_trim($postcode);
        $city = mb_trim($city);
        $country = mb_strtoupper(mb_trim($country));

        self::assertLength('number', $number, self::NUMBER_MAX_LENGTH);
        self::assertRequired('street', $street, self::STREET_MAX_LENGTH);
        self::assertRequired('postcode', $postcode, self::POSTCODE_MAX_LENGTH);
        self::assertRequired('city', $city, self::CITY_MAX_LENGTH);

        if (!Countries::exists($country)) {
            throw InvalidValueObjectException::create(self::class, sprintf('"%s" is not a known ISO 3166-1 alpha-2 country code.', $country));
        }

        if (self::FRANCE === $country && 1 !== preg_match('/^\d{5}$/', $postcode)) {
            throw InvalidValueObjectException::create(self::class, sprintf('"%s" is not a valid French postcode (5 digits expected).', $postcode));
        }

        $this->number = '' === $number ? null : $number;
        $this->street = $street;
        $this->postcode = $postcode;
        $this->city = $city;
        $this->country = $country;
    }

    public function __toString(): string
    {
        $street = null === $this->number ? $this->street : $this->number.' '.$this->street;
        $line = $street.', '.$this->postcode.' '.$this->city;

        return self::FRANCE === $this->country
            ? $line
            : $line.', '.Countries::getName($this->country, 'fr');
    }

    public function equals(self $other): bool
    {
        return $this->number === $other->number
            && $this->street === $other->street
            && $this->postcode === $other->postcode
            && $this->city === $other->city
            && $this->country === $other->country;
    }

    private static function assertRequired(string $field, string $value, int $maxLength): void
    {
        if ('' === $value) {
            throw InvalidValueObjectException::create(self::class, sprintf('"%s" is empty.', $field));
        }

        self::assertLength($field, $value, $maxLength);
    }

    private static function assertLength(string $field, string $value, int $maxLength): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw InvalidValueObjectException::create(self::class, sprintf('"%s" exceeds %d characters.', $field, $maxLength));
        }
    }
}
