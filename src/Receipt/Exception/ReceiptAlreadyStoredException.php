<?php

declare(strict_types=1);

namespace App\Receipt\Exception;

use App\Exception\ExceptionInterface;
use RuntimeException;

use function sprintf;

/**
 * A receipt was about to be written over a PDF that is already filed.
 *
 * It should be unreachable — the key carries the association and a number allocated once
 * — which is exactly why it is refused rather than allowed: a receipt PDF is a tax document
 * someone has been sent, and replacing it silently is the one outcome nobody would notice.
 */
final class ReceiptAlreadyStoredException extends RuntimeException implements ExceptionInterface
{
    public static function at(string $path): self
    {
        return new self(sprintf('A receipt is already stored at "%s"; it will not be overwritten.', $path));
    }
}
