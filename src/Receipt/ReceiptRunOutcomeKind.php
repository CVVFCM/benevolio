<?php

declare(strict_types=1);

namespace App\Receipt;

/**
 * The four ways a volunteer comes out of a yearly run — see ReceiptRunOutcome.
 */
enum ReceiptRunOutcomeKind
{
    case Issued;
    case Skipped;
    case Failed;
    case NotAttempted;
}
