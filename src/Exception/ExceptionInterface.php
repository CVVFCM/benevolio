<?php

declare(strict_types=1);

namespace App\Exception;

use Throwable;

/**
 * Implemented by every exception this application throws, so a caller can catch
 * any project error with a single catch block.
 *
 * Concrete exceptions also extend the closest native PHP exception
 * (\RuntimeException, \InvalidArgumentException, \LogicException, …) so that
 * generic error handling still behaves sensibly.
 */
interface ExceptionInterface extends Throwable
{
}
