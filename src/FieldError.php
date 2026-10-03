<?php

declare(strict_types=1);

namespace HonkMe;

/** One invalid field, from the server (error.fields[]) or from local validation. */
final class FieldError
{
    /**
     * @param string      $field wire name: group_key, metadata.region, Idempotency-Key, body, …
     * @param string      $code  required, too_long, too_short, invalid_enum, invalid_format, out_of_range, not_allowed, invalid_utf8, requires_group_key
     */
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly ?string $message = null,
    ) {
    }
}
