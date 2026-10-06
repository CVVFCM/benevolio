<?php

declare(strict_types=1);

namespace App\State;

use Finite\State;
use Finite\Transition\Transition;

/**
 * Lifecycle of an exercice comptable.
 *
 *     open --close--> closed
 *          <-reopen--
 *
 * **This is what makes a rate trustworthy.** While an exercice is OPEN its rates can be
 * changed and no receipt may be issued from it; once CLOSED the rates, the dates and the name
 * are frozen and receipts can be issued. So the figure printed on a reçu fiscal was decided
 * before the document existed, and nothing can move it afterwards — which is the whole point:
 * a tax receipt has to stay explicable years later, and CGI art. 1740 A penalises a wrongly
 * stated amount at 25%.
 *
 * The dates and the name are frozen too, not only the rates. Moving an exercice's bounds
 * changes *which* contributions it prices, and therefore the amount on a receipt, exactly as
 * surely as editing a rate would.
 *
 * `reopen` exists because closing too early is an ordinary mistake and a mistyped rate has to
 * stay correctable. It is refused once a receipt has been issued for any civil year this
 * exercice prices — see App\State\Listener\FiscalYearReopenGuard. Before that, reopening costs
 * nothing; after it, the rates that produced a filed document must not move.
 */
enum FiscalYearState: string implements State
{
    /** Rates editable, no receipt possible. */
    case OPEN = 'open';

    /** Rates, dates and name frozen; receipts may be issued. */
    case CLOSED = 'closed';

    public const string TRANSITION_CLOSE = 'close';
    public const string TRANSITION_REOPEN = 'reopen';

    /**
     * @return list<Transition>
     */
    public static function getTransitions(): array
    {
        return [
            new Transition(self::TRANSITION_CLOSE, [self::OPEN], self::CLOSED),
            new Transition(self::TRANSITION_REOPEN, [self::CLOSED], self::OPEN),
        ];
    }

    /**
     * Whether the exercice's figures can still be edited.
     *
     * The single question the CRUD and the validator both ask, so "closed means frozen" is
     * stated once.
     */
    public function isEditable(): bool
    {
        return self::OPEN === $this;
    }

    /** Whether receipts may be issued against this exercice's rates. */
    public function allowsReceipts(): bool
    {
        return self::CLOSED === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Ouvert',
            self::CLOSED => 'Clôturé',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::CLOSED => 'success',
        };
    }
}
