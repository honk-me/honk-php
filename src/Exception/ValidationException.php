<?php

declare(strict_types=1);

namespace HonkMe\Exception;

use HonkMe\FieldError;
use Throwable;

/** The message is invalid: rejected locally before sending, or by the server (400, 413, 415, 422). */
class ValidationException extends HonkException
{
    /**
     * @param list<FieldError> $fields every invalid field
     * @param bool             $local  true when rejected before any request was made
     */
    public function __construct(
        string $message,
        public readonly array $fields = [],
        public readonly bool $local = false,
        ?int $status = null,
        ?string $errorCode = 'validation_failed',
        ?string $requestId = null,
        ?string $idempotencyKey = null,
        int $attempts = 0,
        mixed $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $idempotencyKey, $attempts, null, $body, $previous);
    }

    /**
     * @param list<FieldError> $fields
     */
    public static function local(array $fields): self
    {
        $summary = implode('; ', array_map(static fn (FieldError $f) => $f->field . ' ' . ($f->message ?? $f->code), $fields));

        return new self('Invalid Honk message: ' . $summary, $fields, true);
    }
}
