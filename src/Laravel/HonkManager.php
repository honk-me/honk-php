<?php

declare(strict_types=1);

namespace HonkMe\Laravel;

use DateInterval;
use DateTimeInterface;
use HonkMe\Accepted;
use HonkMe\Client;
use HonkMe\Exception\HonkException;
use HonkMe\Helper;
use HonkMe\Laravel\Events\HonkFailed;
use HonkMe\Laravel\Events\HonkSent;
use HonkMe\Laravel\Jobs\SendHonkMessage;
use HonkMe\Laravel\Support\ContextMetadata;
use HonkMe\Message;
use HonkMe\Uuid;
use HonkMe\Wire;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * What the Honk facade resolves to. Every way of sending goes through deliver(), so events,
 * Context metadata and Honk::fake() behave the same for send(), defer(), queue(), the
 * notification channel, exception reports and scheduler hooks.
 *
 * - send(): now, in this process (retries within the call; up to the configured deadline).
 * - defer(): after the HTTP response is sent (or the command/job finishes), no queue needed.
 * - queue(): from a queue worker; survives restarts and retries with the same key.
 */
#[Singleton]
class HonkManager
{
    private ?Client $client = null;

    /**
     * @param array<string, mixed> $config config/honk.php
     */
    public function __construct(
        #[Config('honk')] protected readonly array $config,
        protected readonly Dispatcher $events,
        protected readonly ContextMetadata $context,
    ) {
    }

    /** The client built from config/honk.php (created on first use). */
    public function client(): Client
    {
        return $this->client ??= self::makeClient($this->config);
    }

    /**
     * The client for a notification route: null uses the default; a string is another ingestion
     * key on the same server; an array overrides config keys (url, key, defaults, …); a Client is
     * used as is.
     */
    public function clientFor(mixed $route): Client
    {
        return match (true) {
            $route === null => $this->client(),
            $route instanceof Client => $route,
            is_string($route) => self::makeClient(['key' => $route] + $this->config),
            is_array($route) => self::makeClient(self::stringKeys($route) + $this->config),
            default => throw new InvalidArgumentException('Honk: routeNotificationForHonk() must return null, false, an ingestion key, an array of config overrides or a HonkMe\Client'),
        };
    }

    /**
     * Sends now, in this process. Inside web requests prefer defer() or queue().
     *
     * @param Message|array<string, mixed> $message
     */
    public function send(Message|array $message, ?string $idempotencyKey = null): Accepted
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);

        return $this->deliver($message, $key, null, 'send');
    }

    /** Sends to a notification route (used by HonkChannel). */
    public function sendTo(mixed $route, Message $message, ?string $idempotencyKey = null): Accepted
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);

        return $this->deliver($message, $key, $route, 'notification');
    }

    /**
     * Validates the message now (mistakes surface in your code, not in the worker), fixes its
     * idempotency key, defaults and Context metadata, and dispatches a SendHonkMessage job.
     * Its retries reuse the key, so they never create duplicates. Connection and queue come from
     * Queue::route() (set from honk.queue.connection / honk.queue.queue).
     *
     * @param Message|array<string, mixed> $message
     */
    public function queue(Message|array $message, ?string $idempotencyKey = null, DateTimeInterface|DateInterval|int|null $delay = null): SendHonkMessage
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);
        $job = new SendHonkMessage($this->payload($message), $key);
        if ($delay !== null) {
            $job->delay($delay);
        }
        $this->dispatchJob($job);

        return $job;
    }

    /**
     * Sends after the response: `Honk::defer()->loud('Disk 91%', '/var on app-01')`. The message
     * is validated now. By default it is skipped when the request fails (4xx/5xx), like any
     * Laravel deferred function; pass always: true to send regardless.
     */
    public function defer(bool $always = false): PendingDeferredHonk
    {
        return new PendingDeferredHonk($this, $always);
    }

    /**
     * @internal used by PendingDeferredHonk
     *
     * @param Message|array<string, mixed> $message
     */
    public function deferSend(Message|array $message, ?string $idempotencyKey, bool $always): void
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);
        Wire::build($message, $this->defaults());
        defer(function () use ($message, $key): void {
            // Never throw from a deferred callback: the response is already sent, and a failure
            // here must not loop back through exception reporting.
            try {
                $this->deliver($message, $key, null, 'defer');
            } catch (HonkException $e) {
                report($e); // logged; HonkFailed was dispatched; never honked about (see ExceptionHonker)
            } catch (Throwable $e) {
                logger()->warning('Honk deferred send failed: ' . $e->getMessage());
            }
        }, 'honk:' . $key, $always);
    }

    /**
     * Sends a prepared message and dispatches HonkSent / HonkFailed.
     *
     * @internal used by the queued job, the channel and this class
     */
    public function deliver(Message $message, string $idempotencyKey, mixed $route, string $via): Accepted
    {
        try {
            $accepted = $this->clientFor($route)->send($message, $idempotencyKey);
        } catch (HonkException $e) {
            $this->events->dispatch(new HonkFailed($message, $e, $idempotencyKey, $via));
            throw $e;
        }
        $this->events->dispatch(new HonkSent($message, $accepted, $idempotencyKey, $via));

        return $accepted;
    }

    /** @param array<string, mixed> $options */
    public function problem(string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::problem($groupKey, $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function recovery(string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::recovery($groupKey, $title, $message, $options));
    }

    /**
     * A light honk (info).
     *
     * @param array<string, mixed> $options
     */
    public function light(?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::severity('info', $title, $message, $options));
    }

    /**
     * A beep-beep (success).
     *
     * @param array<string, mixed> $options
     */
    public function beep(?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::severity('success', $title, $message, $options));
    }

    /**
     * A loud honk (warning).
     *
     * @param array<string, mixed> $options
     */
    public function loud(?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::severity('warning', $title, $message, $options));
    }

    /**
     * A long honk (error).
     *
     * @param array<string, mixed> $options
     */
    public function long(?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::severity('error', $title, $message, $options));
    }

    /**
     * A blast (critical).
     *
     * @param array<string, mixed> $options
     */
    public function blast(?string $title, string $message, array $options = []): Accepted
    {
        return $this->send(Helper::severity('critical', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function info(?string $title, string $message, array $options = []): Accepted
    {
        return $this->light($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function success(?string $title, string $message, array $options = []): Accepted
    {
        return $this->beep($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function warning(?string $title, string $message, array $options = []): Accepted
    {
        return $this->loud($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function error(?string $title, string $message, array $options = []): Accepted
    {
        return $this->long($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function critical(?string $title, string $message, array $options = []): Accepted
    {
        return $this->blast($title, $message, $options);
    }

    /** @param array<string, mixed> $config */
    public static function makeClient(array $config): Client
    {
        return new Client(
            url: self::string($config['url'] ?? null),
            key: self::string($config['key'] ?? null),
            timeoutMs: (int) round(self::number($config['timeout'] ?? null, 5) * 1000),
            retries: (int) self::number($config['retries'] ?? null, 4),
            deadlineMs: (int) round(self::number($config['deadline'] ?? null, 30) * 1000),
            defaults: self::defaultsFrom($config),
            userAgent: 'laravel',
        );
    }

    protected function dispatchJob(SendHonkMessage $job): void
    {
        dispatch($job);
    }

    /**
     * Converts arrays, adds Context metadata and fixes the idempotency key.
     *
     * @param Message|array<string, mixed> $message
     *
     * @return array{Message, string}
     */
    protected function prepare(Message|array $message, ?string $idempotencyKey): array
    {
        $message = is_array($message) ? Message::fromArray($message) : $message;
        $key = Wire::checkIdempotencyKey($idempotencyKey ?? $message->idempotencyKey ?? Uuid::v7());

        return [$this->context->apply($message), $key];
    }

    /**
     * The validated message as camelCase fields with defaults applied (what the job stores).
     *
     * @return array<string, mixed>
     */
    protected function payload(Message $message): array
    {
        $fields = array_flip(Message::FIELDS);
        $payload = [];
        foreach (Wire::build($message, $this->defaults()) as $wire => $value) {
            $payload[$fields[$wire]] = $value;
        }

        return $payload;
    }

    /** @return array<string, string> */
    protected function defaults(): array
    {
        return self::defaultsFrom($this->config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private static function defaultsFrom(array $config): array
    {
        $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
        $out = [];
        foreach (['source', 'environment', 'channel'] as $field) {
            if (is_string($defaults[$field] ?? null) && $defaults[$field] !== '') {
                $out[$field] = $defaults[$field];
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function number(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
