<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Exception\InvalidValueObjectException;
use App\ValueObject\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    #[Test]
    public function it_keeps_a_valid_french_address(): void
    {
        $address = new Address('12 bis', 'rue des Jardins', '44000', 'Nantes', 'FR');

        self::assertSame('12 bis', $address->number);
        self::assertSame('rue des Jardins', $address->street);
        self::assertSame('44000', $address->postcode);
        self::assertSame('Nantes', $address->city);
        self::assertSame('FR', $address->country);
    }

    /**
     * Plenty of French addresses are a lieu-dit with no street number; refusing
     * them would lock those volunteers out of declaring.
     */
    #[Test]
    public function it_accepts_a_missing_street_number(): void
    {
        self::assertNull(new Address(null, 'Lieu-dit Le Moulin', '44190', 'Clisson', 'FR')->number);
        self::assertNull(new Address('   ', 'Lieu-dit Le Moulin', '44190', 'Clisson', 'FR')->number);
    }

    #[Test]
    public function it_normalises_the_country_code(): void
    {
        self::assertSame('BE', new Address('1', 'rue Neuve', '1000', 'Bruxelles', ' be ')->country);
    }

    #[Test]
    public function it_renders_a_readable_line(): void
    {
        self::assertSame(
            '12 rue des Jardins, 44000 Nantes',
            (string) new Address('12', 'rue des Jardins', '44000', 'Nantes', 'FR'),
        );
        self::assertSame(
            'Lieu-dit Le Moulin, 44190 Clisson',
            (string) new Address(null, 'Lieu-dit Le Moulin', '44190', 'Clisson', 'FR'),
        );
        // Only a foreign address needs its country spelled out.
        self::assertSame(
            'rue Neuve, 1000 Bruxelles, Belgique',
            (string) new Address(null, 'rue Neuve', '1000', 'Bruxelles', 'BE'),
        );
    }

    #[Test]
    public function it_compares_by_value(): void
    {
        $address = new Address('12', 'rue des Jardins', '44000', 'Nantes', 'FR');

        self::assertTrue($address->equals(new Address('12', 'rue des Jardins', '44000', 'Nantes', 'FR')));
        self::assertFalse($address->equals(new Address('13', 'rue des Jardins', '44000', 'Nantes', 'FR')));
    }

    /**
     * A non-French postcode is not checked against a national format — only that
     * it is present. Belgian postcodes are 4 digits, Dutch ones "1234 AB".
     */
    #[Test]
    public function it_accepts_a_foreign_postcode_format(): void
    {
        self::assertSame('1000', new Address(null, 'rue Neuve', '1000', 'Bruxelles', 'BE')->postcode);
        self::assertSame('1012 AB', new Address(null, 'Damstraat', '1012 AB', 'Amsterdam', 'NL')->postcode);
    }

    #[Test]
    #[DataProvider('invalidAddresses')]
    public function it_refuses_an_invalid_address(
        ?string $number,
        string $street,
        string $postcode,
        string $city,
        string $country,
    ): void {
        $this->expectException(InvalidValueObjectException::class);

        new Address($number, $street, $postcode, $city, $country);
    }

    /**
     * @return iterable<string, array{?string, string, string, string, string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'blank street' => [null, '  ', '44000', 'Nantes', 'FR'];
        yield 'blank postcode' => [null, 'rue des Jardins', '', 'Nantes', 'FR'];
        yield 'blank city' => [null, 'rue des Jardins', '44000', ' ', 'FR'];
        yield 'unknown country' => [null, 'rue des Jardins', '44000', 'Nantes', 'ZZ'];
        yield 'blank country' => [null, 'rue des Jardins', '44000', 'Nantes', ''];
        yield 'french postcode too short' => [null, 'rue des Jardins', '4400', 'Nantes', 'FR'];
        yield 'french postcode not numeric' => [null, 'rue des Jardins', '44A00', 'Nantes', 'FR'];
        yield 'street too long' => [null, str_repeat('a', Address::STREET_MAX_LENGTH + 1), '44000', 'Nantes', 'FR'];
        yield 'number too long' => [str_repeat('1', Address::NUMBER_MAX_LENGTH + 1), 'rue X', '44000', 'Nantes', 'FR'];
    }
}
