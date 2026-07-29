<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\FiscalYear;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

use function assert;
use function sprintf;

/**
 * Hands out the *numéro d'ordre du reçu*.
 *
 * `2026-0001`, continuous within an exercice and **never reused** — which is not a
 * nicety: a tax receipt is a numbered document, and a repeated number makes two
 * receipts indistinguishable in an audit.
 *
 * The number comes from a counter on the exercice, taken under a **pessimistic write
 * lock** on that row. Without the lock, two treasurers validating at the same instant
 * both read the same counter and both get `2026-0001`; the unique index would then turn
 * one of them into an error rather than into the next number. Locking is what makes the
 * second one simply wait and receive `2026-0002`.
 *
 * The caller owns the transaction. Locking outside one would be pointless — the lock
 * would be released before the number was used.
 */
final readonly class ReceiptNumberAllocator
{
    /** Zero-padded so receipts sort lexicographically in a bucket listing. */
    private const int SEQUENCE_PADDING = 4;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function allocate(FiscalYear $fiscalYear): string
    {
        // Re-read the row under a write lock. find() with a lock mode issues
        // SELECT ... FOR UPDATE, so a second caller blocks here rather than reading a
        // stale counter.
        $locked = $this->entityManager->find(
            FiscalYear::class,
            $fiscalYear->getId(),
            LockMode::PESSIMISTIC_WRITE,
        );

        // The exercice was handed to us, so it exists; the lock re-read cannot fail
        // unless it was deleted mid-transaction, which the FK prevents.
        assert($locked instanceof FiscalYear);

        $sequence = $locked->takeNextReceiptSequence();

        // Flushed inside the caller's transaction, so the counter and the receipt that
        // uses it commit together or not at all.
        $this->entityManager->flush();

        return sprintf(
            '%s-%0'.self::SEQUENCE_PADDING.'d',
            $locked->getName(),
            $sequence,
        );
    }
}
