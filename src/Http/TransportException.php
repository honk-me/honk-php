<?php

declare(strict_types=1);

namespace HonkMe\Http;

use RuntimeException;

/** The request did not get an HTTP answer (connection error or timeout). */
final class TransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
