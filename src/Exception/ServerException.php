<?php

declare(strict_types=1);

namespace HonkMe\Exception;

/** 5xx on every attempt until the deadline (for example 503 unavailable). */
class ServerException extends HonkException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
