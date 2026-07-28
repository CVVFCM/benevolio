<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Person;
use App\ValueObject\Address;
use App\ValueObject\Email;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Person>
 */
final class PersonFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Person::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'organization' => OrganizationFactory::new(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'email' => new Email(self::faker()->unique()->safeEmail()),
            // A valid French postcode: Address refuses anything but 5 digits for FR,
            // and Faker's postcode() happily produces other shapes.
            'address' => new Address(
                (string) self::faker()->numberBetween(1, 200),
                self::faker()->streetName(),
                (string) self::faker()->numberBetween(10000, 99999),
                self::faker()->city(),
                'FR',
            ),
        ];
    }
}
