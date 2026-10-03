<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Events;

use HonkMe\Exception\HonkException;
use HonkMe\Message;

/** A send failed after its retries. Retryable failures can be sent again with the same key. */
final class HonkFailed
{
    /**
     * @param string $via send, defer, queue or notification
     */
    public function __construct(
        public readonly Message $message,
        public readonly HonkException $exception,
        public readonly string $idempotencyKey,
        public readonly string $via,
    ) {
    }
}
