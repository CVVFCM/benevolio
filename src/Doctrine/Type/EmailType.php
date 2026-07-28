<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Exception\InvalidValueObjectException;
use App\ValueObject\Email;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

use function is_string;

/**
 * Persists App\ValueObject\Email in a single VARCHAR column.
 *
 * Registered as "app_email" under doctrine.dbal.types, the same way the Symfony
 * bridge's "uuid" type is. A one-value object does not deserve an embeddable and
 * the awkward `email_value` column name that would come with it.
 */
final class EmailType extends StringType
{
    public const string NAME = 'app_email';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] ??= Email::MAX_LENGTH;

        return parent::getSQLDeclaration($column, $platform);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Email) {
            return $value->value;
        }

        if (is_string($value)) {
            // Round-trip through the value object so an invalid address cannot be
            // written by passing a raw string.
            return new Email($value)->value;
        }

        throw InvalidType::new($value, self::NAME, ['null', 'string', Email::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Email
    {
        if (null === $value || $value instanceof Email) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Email::class]);
        }

        try {
            return new Email($value);
        } catch (InvalidValueObjectException $e) {
            throw ValueNotConvertible::new($value, self::NAME, null, $e);
        }
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
