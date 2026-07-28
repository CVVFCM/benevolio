<?php

declare(strict_types=1);

namespace App\Declaration;

use Stringable;

/**
 * The secret carried in the confirmation link emailed to a volunteer.
 *
 * 32 random bytes, base64url-encoded so it survives a URL untouched.
 *
 * STORED AS-IS, NOT HASHED — a deliberate choice. Hashing would need a separate
 * plaintext selector to stay findable, and the threat it defends against is an
 * attacker who can already read the database, who therefore already has the
 * declarations themselves. Single use plus a short expiry is the protection that
 * matters here, and both live on App\Entity\Declaration.
 *
 * There is no equals(): tokens are matched by the unique index on the column, so
 * no PHP comparison — timing-safe or otherwise — ever happens.
 */
final readonly class ConfirmationToken implements Stringable
{
    public const int MAX_LENGTH = 64;
    /**
     * Long enough that guessing is hopeless, short enough for a readable URL.
     */
    private const int BYTES = 32;

    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }
}
