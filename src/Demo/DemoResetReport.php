<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Organization;

/**
 * What a reset did, for the command to print.
 */
final readonly class DemoResetReport
{
    /**
     * @param list<string> $undeletedReceiptPaths PDFs left in storage: the rows are gone, and
     *                                            the counter never hands their numbers out again,
     *                                            so nothing reads them — they are only litter
     */
    public function __construct(
        public Organization $organization,
        public bool $created,
        public int $deletedReceipts,
        public array $undeletedReceiptPaths,
    ) {
    }
}
