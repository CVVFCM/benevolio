<?php

declare(strict_types=1);

namespace App\State\Listener;

use App\Entity\FiscalYear;
use App\Repository\ReceiptRepository;
use App\State\FiscalYearState;
use Finite\Event\CanTransitionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Refuses to reopen an exercice once it has priced a reçu fiscal.
 *
 * Reopening exists because closing too early is an ordinary mistake and a mistyped rate has to
 * stay correctable. But the moment a receipt has been issued, the rates that produced its
 * amount must not move: the figure is frozen on the row and printed on a document a volunteer
 * may already have filed with their tax return, so a rate change afterwards would leave a
 * number nobody can reconstruct or defend.
 *
 * A guard rather than a check in the controller, for the same reason as
 * App\State\Listener\DeclarationTransitionGuard: `finite_can()` then reports it in Twig, so the
 * action disappears from the page instead of failing when clicked.
 *
 * **Which receipts count.** An exercice prices every civil year it is the FIRST to intersect —
 * see App\Repository\FiscalYearRepository::findFirstForCivilYear(). 2025-2026 prices civil 2026,
 * so a 2026 receipt locks it. Asked of the repository rather than recomputed here.
 */
final readonly class FiscalYearReopenGuard
{
    public function __construct(
        private ReceiptRepository $receipts,
    ) {
    }

    #[AsEventListener(event: CanTransitionEvent::class)]
    public function __invoke(CanTransitionEvent $event): void
    {
        $fiscalYear = $event->getObject();

        if (!$fiscalYear instanceof FiscalYear) {
            return;
        }

        if (FiscalYearState::TRANSITION_REOPEN !== $event->getTransition()->getName()) {
            return;
        }

        if ($this->receipts->existsForCivilYearsPricedBy($fiscalYear)) {
            $event->blockTransition();
        }
    }
}
