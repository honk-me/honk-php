<?php

declare(strict_types=1);

namespace HonkMe\Exception;

/** 409 idempotency_conflict: the idempotency key was already used with a different payload in the last 24 hours. */
class ConflictException extends HonkException
{
}
