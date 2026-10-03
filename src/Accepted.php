<?php

declare(strict_types=1);

namespace HonkMe;

use DateTimeImmutable;

/** The 202 answer: the message is durably stored (which does not mean a push was delivered). */
final class Accepted
{
    /**
     * @param string            $id         message id (msg_…); the original id when $duplicate is true
     * @param bool              $duplicate  this idempotency key was already accepted with the same payload (24 h)
     * @param DateTimeImmutable $receivedAt when the server accepted it (the first time, for a duplicate)
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $duplicate,
        public readonly DateTimeImmutable $receivedAt,
    ) {
    }

    /** @return array{id: string, duplicate: bool, receivedAt: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'duplicate' => $this->duplicate, 'receivedAt' => $this->receivedAt->format('Y-m-d\TH:i:s.v\Z')];
    }
}
