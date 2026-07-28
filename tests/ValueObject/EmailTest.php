<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Exception\InvalidValueObjectException;
use App\ValueObject\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[Test]
    public function it_keeps_a_valid_address(): void
    {
        self::assertSame('jean.dupont@example.test', new Email('jean.dupont@example.test')->value);
    }

    #[Test]
    public function it_trims_and_lowercases(): void
    {
        // Lowercasing is what makes "one Person per email per Organization" hold:
        // a volunteer will type Jean.Dupont@ one year and jean.dupont@ the next.
        self::assertSame('jean.dupont@example.test', new Email('  Jean.Dupont@Example.TEST  ')->value);
    }

    #[Test]
    public function it_is_stringable_and_comparable(): void
    {
        $email = new Email('a@example.test');

        self::assertSame('a@example.test', (string) $email);
        self::assertTrue($email->equals(new Email('A@example.test')));
        self::assertFalse($email->equals(new Email('b@example.test')));
    }

    #[Test]
    #[DataProvider('invalidAddresses')]
    public function it_refuses_an_invalid_address(string $value): void
    {
        $this->expectException(InvalidValueObjectException::class);

        new Email($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no at sign' => ['jean.dupont'];
        yield 'no domain' => ['jean@'];
        yield 'no local part' => ['@example.test'];
        yield 'spaces inside' => ['jean dupont@example.test'];
        yield 'too long' => [str_repeat('a', Email::MAX_LENGTH).'@example.test'];
    }
}
