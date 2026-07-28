<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Exception\InvalidValueObjectException;
use Stringable;

use function sprintf;

use const FILTER_VALIDATE_EMAIL;

/**
 * A syntactically valid email address.
 *
 * Persisted through App\Doctrine\Type\EmailType as a single column. Doctrine
 * rebuilds it on read through that type, so the invariant below is checked on
 * both write and read.
 */
final readonly class Email implements Stringable
{
    public const int MAX_LENGTH = 180;

    public string $value;

    public function __construct(string $value)
    {
        $value = mb_trim($value);

        if ('' === $value) {
            throw InvalidValueObjectException::create(self::class, 'the address is empty.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidValueObjectException::create(self::class, sprintf('the address exceeds %d characters.', self::MAX_LENGTH));
        }

        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw InvalidValueObjectException::create(self::class, sprintf('"%s" is not a valid address.', $value));
        }

        // Only the domain is case-insensitive per RFC 5321, but treating the whole
        // address as lowercase is what makes "one Person per email per
        // Organization" actually hold: no real mail provider distinguishes
        // Jean.Dupont@ from jean.dupont@, and a volunteer will type both.
        $this->value = mb_strtolower($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
