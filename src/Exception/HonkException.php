<?php

declare(strict_types=1);

namespace HonkMe\Exception;

use RuntimeException;
use Throwable;

/**
 * Base class of every exception thrown by Client::send() and the helpers.
 *
 * Retryable exceptions (network, timeout, 5xx, quota) can be retried later with the same
 * $idempotencyKey without creating duplicates.
 */
class HonkException extends RuntimeException
{
    /**
     * @param int|null    $status         HTTP status, when the server answered
     * @param string|null $errorCode      API error code (invalid_key, quota_exceeded, …) or network_error / timeout
     * @param string|null $requestId      server request id (req_…)
     * @param string|null $idempotencyKey the Idempotency-Key that was used
     * @param int         $attempts       HTTP attempts made (0 when rejected locally)
     * @param int|null    $retryAfter     seconds from the Retry-After header
     * @param mixed       $body           decoded error body, when JSON
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly int $attempts = 0,
        public readonly ?int $retryAfter = null,
        public readonly mixed $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True when sending the same event again later (same idempotency key) may succeed. */
    public function isRetryable(): bool
    {
        return false;
    }
}
