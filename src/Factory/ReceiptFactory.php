<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Receipt;
use App\ValueObject\Money;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function sprintf;

/**
 * A receipt row, for tests that read receipts rather than issue them. Issuing goes through
 * App\Receipt\YearlyReceiptRun; this writes no PDF — pass a `storagePath` that exists when
 * the test downloads it.
 *
 * @extends PersistentObjectFactory<Receipt>
 */
final class ReceiptFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Receipt::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'person' => PersonFactory::new(),
            'year' => 2025,
            'number' => sprintf('%04d', self::faker()->unique()->numberBetween(1, 9999)),
            'amount' => new Money(4325),
            'storagePath' => '2025/cerfa-test.pdf',
            'volunteerName' => self::faker()->name(),
            'volunteerAddress' => self::faker()->address(),
            'issuedAt' => new DateTimeImmutable('2026-01-15'),
        ];
    }
}
