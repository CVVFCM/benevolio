<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Organization;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

use function assert;
use function sprintf;

/**
 * Hands out the *numéro d'ordre du reçu*.
 *
 * `0001`, one continuous series per association whatever year the receipt covers, and
 * **never reused** — which is not a nicety: a tax receipt is a numbered document, and a
 * repeated number makes two receipts indistinguishable in an audit.
 *
 * The number comes from a counter on the association, taken under a **pessimistic write
 * lock** on that row. Without the lock, two runs starting at the same instant both read the
 * same counter and both get `0001`; the unique index would then turn one of them into an
 * error rather than into the next number. Locking is what makes the second one simply wait
 * and receive `0002`.
 *
 * The caller owns the transaction. Locking outside one would be pointless — the lock would
 * be released before the number was used.
 */
final readonly class ReceiptNumberAllocator
{
    /** Zero-padded so receipts sort lexicographically in a bucket listing. */
    private const int SEQUENCE_PADDING = 4;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function allocate(Organization $organization): string
    {
        // Re-read the row under a write lock. find() with a lock mode issues
        // SELECT ... FOR UPDATE, so a second caller blocks here rather than reading a
        // stale counter.
        $locked = $this->entityManager->find(
            Organization::class,
            $organization->getId(),
            LockMode::PESSIMISTIC_WRITE,
        );

        // The association was handed to us, so it exists; the lock re-read cannot fail
        // unless it was deleted mid-transaction, which the FK prevents.
        assert($locked instanceof Organization);

        $sequence = $locked->takeNextReceiptSequence();

        // Flushed inside the caller's transaction, so the counter and the receipt that
        // uses it commit together or not at all.
        $this->entityManager->flush();

        // Padded so receipts sort lexicographically in a bucket listing; sprintf widens
        // past the padding rather than truncating, so number 10 000 is `10000`, not `0000`.
        return sprintf('%0'.self::SEQUENCE_PADDING.'d', $sequence);
    }
}
