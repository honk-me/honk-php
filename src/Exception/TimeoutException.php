<?php

declare(strict_types=1);

namespace HonkMe\Exception;

/**
 * Attempts timed out until the deadline. The event may or may not have been stored; retrying
 * with the same idempotency key is safe.
 */
class TimeoutException extends NetworkException
{
}
