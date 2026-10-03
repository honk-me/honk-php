<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Testing;

use DateTimeImmutable;
use HonkMe\Accepted;
use HonkMe\Laravel\HonkManager;
use HonkMe\Laravel\Jobs\SendHonkMessage;
use HonkMe\Message;
use HonkMe\Severity;
use HonkMe\Wire;
use Illuminate\Support\Testing\Fakes\Fake;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Honk::fake(): records instead of sending, like Mail::fake() / Notification::fake().
 *
 * Messages are validated exactly like real sends (an invalid message still throws), defaults
 * and Context metadata are applied, and the recorded copy has a canonical Severity case, so
 * `fn (Message $m) => $m->severity === Severity::Loud` works whatever spelling was used.
 *
 * - sent: send(), the helpers, Honk::defer() (recorded immediately) and the notification channel;
 * - queued: Honk::queue() (nothing is dispatched).
 */
class HonkFake extends HonkManager implements Fake
{
    /** @var list<array{message: Message, key: string, via: string}> */
    private array $records = [];

    private int $counter = 0;

    public function deliver(Message $message, string $idempotencyKey, mixed $route, string $via): Accepted
    {
        $this->record($message, $idempotencyKey, $via);

        return new Accepted(sprintf('msg_fake%020d', ++$this->counter), false, new DateTimeImmutable());
    }

    public function deferSend(Message|array $message, ?string $idempotencyKey, bool $always): void
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);
        $this->record($message, $key, 'defer');
    }

    protected function dispatchJob(SendHonkMessage $job): void
    {
        $this->record(Message::fromArray($job->message), $job->idempotencyKey, 'queue');
    }

    /**
     * Messages sent (send, helpers, defer, notifications), optionally filtered.
     *
     * @param (callable(Message, string): bool)|null $callback receives the message and its idempotency key
     *
     * @return list<Message>
     */
    public function sent(?callable $callback = null): array
    {
        return $this->filter(fn (string $via) => $via !== 'queue', $callback);
    }

    /**
     * Messages passed to Honk::queue(), optionally filtered.
     *
     * @param (callable(Message, string): bool)|null $callback
     *
     * @return list<Message>
     */
    public function queued(?callable $callback = null): array
    {
        return $this->filter(fn (string $via) => $via === 'queue', $callback);
    }

    /** @param (callable(Message, string): bool)|null $callback */
    public function assertSent(?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->sent($callback), $callback === null ? 'No Honk message was sent.' : 'No sent Honk message matched the given callback.');
    }

    /** @param (callable(Message, string): bool)|null $callback */
    public function assertSentTimes(int $times, ?callable $callback = null): void
    {
        $count = count($this->sent($callback));
        PHPUnit::assertSame($times, $count, "Expected {$times} Honk message(s) to be sent, but {$count} were.");
    }

    /** @param callable(Message, string): bool $callback */
    public function assertNotSent(callable $callback): void
    {
        PHPUnit::assertEmpty($this->sent($callback), 'An unexpected Honk message was sent.');
    }

    public function assertNothingSent(): void
    {
        $count = count($this->sent());
        PHPUnit::assertSame(0, $count, "Expected no Honk messages to be sent, but {$count} were.");
    }

    /**
     * Without a callback: at least one message was queued. An int asserts the exact count.
     *
     * @param (callable(Message, string): bool)|int|null $callback
     */
    public function assertQueued(callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            $count = count($this->queued());
            PHPUnit::assertSame($callback, $count, "Expected {$callback} Honk message(s) to be queued, but {$count} were.");

            return;
        }
        PHPUnit::assertNotEmpty($this->queued($callback), $callback === null ? 'No Honk message was queued.' : 'No queued Honk message matched the given callback.');
    }

    public function assertNothingQueued(): void
    {
        $count = count($this->queued());
        PHPUnit::assertSame(0, $count, "Expected no Honk messages to be queued, but {$count} were.");
    }

    /** Neither sent nor queued. */
    public function assertNothingOutgoing(): void
    {
        $this->assertNothingSent();
        $this->assertNothingQueued();
    }

    private function record(Message $message, string $key, string $via): void
    {
        $this->records[] = ['message' => $this->normalized($message), 'key' => $key, 'via' => $via];
    }

    /**
     * A validated copy with defaults applied and a canonical Severity case.
     */
    private function normalized(Message $message): Message
    {
        $fields = array_flip(Message::FIELDS);
        $values = [];
        foreach (Wire::build($message, $this->defaults()) as $wire => $value) {
            $values[$fields[$wire]] = $value;
        }
        $copy = Message::fromArray($values);
        if (is_string($copy->severity)) {
            $copy->severity = Severity::tryParse($copy->severity) ?? $copy->severity;
        }
        $copy->idempotencyKey = $message->idempotencyKey;

        return $copy;
    }

    /**
     * @param callable(string): bool                   $via
     * @param (callable(Message, string): bool)|null $callback
     *
     * @return list<Message>
     */
    private function filter(callable $via, ?callable $callback): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($via($record['via']) && ($callback === null || $callback($record['message'], $record['key']))) {
                $out[] = $record['message'];
            }
        }

        return $out;
    }
}
