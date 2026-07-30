<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Issue the CERFA receipt for a validated declaration.
 *
 * Carries the id, not the entity. The message is routed to the `sync` transport today,
 * so an object would survive — but the whole point of going through Messenger is that
 * moving to a real transport later is a routing change and nothing else, and a real
 * transport serialises. An id also guarantees the handler reads the declaration as it is
 * when it runs, rather than as it was when the transition fired.
 */
final readonly class IssueReceipt
{
    public function __construct(
        public Uuid $declarationId,
    ) {
    }
}
