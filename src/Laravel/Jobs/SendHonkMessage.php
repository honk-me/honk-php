<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Jobs;

use HonkMe\Exception\HonkException;
use HonkMe\Exception\QuotaException;
use HonkMe\Laravel\HonkManager;
use HonkMe\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * Sends one validated message, dispatched by Honk::queue(). The idempotency key is fixed at
 * dispatch time, so every retry (by the client and by the queue) is duplicate-free. Retryable
 * failures are rethrown for the queue's backoff; a daily quota releases the job until the reset;
 * invalid messages, auth errors and conflicts fail the job at once.
 *
 * Tries/backoff default to the attributes below; honk.queue.tries / honk.queue.backoff override
 * them. Connection and queue come from Queue::route() (honk.queue.connection / .queue).
 */
#[Tries(5)]
#[Backoff(10, 60, 300, 900)]
final class SendHonkMessage implements ShouldQueue
{
    use Queueable;

    public ?int $tries = null;

    /** @var list<int>|int|null */
    public array|int|null $backoff = null;

    /**
     * @param array<string, mixed> $message camelCase fields, defaults and context applied
     */
    public function __construct(public array $message, public string $idempotencyKey)
    {
        $tries = config('honk.queue.tries');
        if (is_numeric($tries)) {
            $this->tries = (int) $tries;
        }
        $backoff = config('honk.queue.backoff');
        if (is_array($backoff) && $backoff !== []) {
            $this->backoff = array_values(array_map(static fn ($s) => is_numeric($s) ? (int) $s : 0, $backoff));
        }
    }

    public function handle(HonkManager $honk): void
    {
        try {
            $honk->deliver(Message::fromArray($this->message), $this->idempotencyKey, null, 'queue');
        } catch (QuotaException $e) {
            // Daily quota: wait for the reset instead of burning tries on the normal backoff.
            if ($this->job !== null && $e->retryAfter !== null && $this->attempts() < ($this->tries ?? 5)) {
                $this->release(min(86400, max(1, $e->retryAfter)));

                return;
            }
            throw $e;
        } catch (HonkException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $this->fail($e);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['honk', 'honk:' . $this->idempotencyKey];
    }
}
