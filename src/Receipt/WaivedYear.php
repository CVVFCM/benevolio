<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\DeclarationAction;
use App\Entity\Person;
use DateTimeImmutable;
use RuntimeException;

/**
 * One volunteer's waived expenses over a civil year, accumulated line by line.
 *
 * Mutable, deliberately, and the only mutable thing in this namespace: it exists to be
 * filled in a single pass over the year's lines. App\Receipt\YearlyReceiptRun builds these
 * and then reads them; nothing else should see one.
 */
final class WaivedYear
{
    public int $amountCents = 0;

    private ?DateTimeImmutable $lastWaivedDay = null;

    public function __construct(
        public readonly Person $person,
    ) {
    }

    /**
     * @param int $mileageCents what the line is worth under the exercice pricing this civil
     *                          year — never null: App\Receipt\YearlyReceiptRun refuses the whole
     *                          run when no exercice covers the year, so there is no such thing
     *                          here as a line nobody could price
     */
    public function add(DeclarationAction $action, int $mileageCents): void
    {
        if (0 === $mileageCents) {
            // Declared, but nothing was waived — hours only, or travel in someone else's
            // vehicle. It belongs to the ledger, not to a receipt.
            return;
        }

        $this->amountCents += $mileageCents;

        // The latest day the volunteer actually incurred waived expenses. The lines arrive
        // in date order, so this is a comparison and not a sort — see
        // DeclarationActionRepository::findValidatedInCivilYear().
        $end = $action->getEndDate();

        if (null === $this->lastWaivedDay || $end > $this->lastWaivedDay) {
            $this->lastWaivedDay = $end;
        }
    }

    /**
     * The date printed in « Date du versement ou du don ».
     *
     * Only ever asked for when something was waived, which is when it is guaranteed to
     * exist — hence an exception rather than a nullable return.
     */
    public function lastWaivedDay(): DateTimeImmutable
    {
        return $this->lastWaivedDay ?? throw new RuntimeException('No waived day: this volunteer has nothing to receipt.');
    }
}
