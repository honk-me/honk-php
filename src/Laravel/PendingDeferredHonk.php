<?php

declare(strict_types=1);

namespace HonkMe\Laravel;

use HonkMe\Helper;
use HonkMe\Message;

/**
 * Returned by Honk::defer(): the same helpers, sent after the response via Laravel's defer().
 */
final class PendingDeferredHonk
{
    public function __construct(private readonly HonkManager $honk, private readonly bool $always = false)
    {
    }

    /** @param Message|array<string, mixed> $message */
    public function send(Message|array $message, ?string $idempotencyKey = null): void
    {
        $this->honk->deferSend($message, $idempotencyKey, $this->always);
    }

    /** @param array<string, mixed> $options */
    public function problem(string $groupKey, ?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::problem($groupKey, $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function recovery(string $groupKey, ?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::recovery($groupKey, $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function light(?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::severity('info', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function beep(?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::severity('success', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function loud(?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::severity('warning', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function long(?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::severity('error', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function blast(?string $title, string $message, array $options = []): void
    {
        $this->send(Helper::severity('critical', $title, $message, $options));
    }
}
