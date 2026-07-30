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

    /**
     * Lines with no exercice to price them, which are therefore absent from the amount.
     * Carried through to the report rather than silently dropped.
     */
    public int $unvaluedLineCount = 0;

    private ?DateTimeImmutable $lastWaivedDay = null;

    public function __construct(
        public readonly Person $person,
    ) {
    }

    /**
     * @param int|null $mileageCents what the line is worth, or null when no exercice covers
     *                               its date and there is therefore no barème to price it
     */
    public function add(DeclarationAction $action, ?int $mileageCents): void
    {
        if (null === $mileageCents) {
            ++$this->unvaluedLineCount;

            return;
        }

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
