<?php

declare(strict_types=1);

namespace HonkMe\Exception;

/** Honk could not be reached (or the connection broke) on every attempt until the deadline. */
class NetworkException extends HonkException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
