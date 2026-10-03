<?php

declare(strict_types=1);

namespace HonkMe;

use DateTimeInterface;
use HonkMe\Exception\ValidationException;

/**
 * Fluent builder for one Honk event. Only the message text is required.
 *
 *     Message::make('Ana (Acme) asked for a quote')
 *         ->title('New request')
 *         ->priority('high')
 *         ->category('customers')
 *         ->groupKey('requests/4812')
 *         ->url('https://shop.example.com/admin/requests/4812');
 *
 * Fields mirror POST /v1/messages in camelCase (groupKey → group_key). Null fields are omitted.
 */
class Message
{
    public ?string $title = null;
    public ?string $message = null;
    public Severity|string|null $severity = null;
    public ?string $priority = null;
    public ?string $category = null;
    public ?string $source = null;
    public ?string $environment = null;
    public ?string $channel = null;
    public ?string $groupKey = null;
    public ?string $eventType = null;
    public DateTimeInterface|string|null $occurredAt = null;
    public ?string $url = null;
    public ?string $imageUrl = null;
    /** @var array<array-key, mixed>|null string keys and string/int/float/bool values; checked when sent */
    public ?array $metadata = null;
    public ?int $ttlSeconds = null;
    public ?int $sourceSequence = null;
    /** Optional stable key for this event; reused on every retry. */
    public ?string $idempotencyKey = null;

    /** Field names accepted by fromArray()/toArray(), camelCase => wire name. */
    public const FIELDS = [
        'title' => 'title',
        'message' => 'message',
        'severity' => 'severity',
        'priority' => 'priority',
        'category' => 'category',
        'source' => 'source',
        'environment' => 'environment',
        'channel' => 'channel',
        'groupKey' => 'group_key',
        'eventType' => 'event_type',
        'occurredAt' => 'occurred_at',
        'url' => 'url',
        'imageUrl' => 'image_url',
        'metadata' => 'metadata',
        'ttlSeconds' => 'ttl_seconds',
        'sourceSequence' => 'source_sequence',
    ];

    final public function __construct(?string $message = null, ?string $title = null)
    {
        $this->message = $message;
        $this->title = $title;
    }

    public static function make(?string $message = null, ?string $title = null): static
    {
        return new static($message, $title);
    }

    /** Alias of make(). */
    public static function create(?string $message = null, ?string $title = null): static
    {
        return new static($message, $title);
    }

    /**
     * Builds a message from camelCase fields (plus optional 'idempotencyKey'). Unknown keys and
     * values of the wrong type are rejected, with a hint for snake_case names.
     *
     * @param array<array-key, mixed> $fields
     */
    public static function fromArray(array $fields): static
    {
        $m = new static();
        $errors = [];
        $wireToField = array_flip(self::FIELDS);
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if ($key !== 'idempotencyKey' && !array_key_exists($key, self::FIELDS)) {
                $hint = isset($wireToField[$key]) ? " (use {$wireToField[$key]})" : '';
                $errors[] = new FieldError($key, 'not_allowed', 'unknown field' . $hint);
                continue;
            }
            if ($value === null || $m->assign($key, $value)) {
                continue;
            }
            $errors[] = new FieldError(self::FIELDS[$key] ?? 'Idempotency-Key', 'invalid_format', 'has the wrong type (' . get_debug_type($value) . ')');
        }
        if ($errors !== []) {
            throw ValidationException::local($errors);
        }

        return $m;
    }

    /** Sets one field if the value has an acceptable type. */
    private function assign(string $field, mixed $value): bool
    {
        if (is_string($value)) {
            match ($field) {
                'title' => $this->title = $value,
                'message' => $this->message = $value,
                'severity' => $this->severity = $value,
                'priority' => $this->priority = $value,
                'category' => $this->category = $value,
                'source' => $this->source = $value,
                'environment' => $this->environment = $value,
                'channel' => $this->channel = $value,
                'groupKey' => $this->groupKey = $value,
                'eventType' => $this->eventType = $value,
                'occurredAt' => $this->occurredAt = $value,
                'url' => $this->url = $value,
                'imageUrl' => $this->imageUrl = $value,
                'idempotencyKey' => $this->idempotencyKey = $value,
                default => null,
            };

            return !in_array($field, ['metadata', 'ttlSeconds', 'sourceSequence'], true);
        }

        if ($field === 'severity' && $value instanceof Severity) {
            $this->severity = $value;
        } elseif ($field === 'occurredAt' && $value instanceof DateTimeInterface) {
            $this->occurredAt = $value;
        } elseif ($field === 'metadata' && is_array($value)) {
            $this->metadata = $value;
        } elseif ($field === 'ttlSeconds' && is_int($value)) {
            $this->ttlSeconds = $value;
        } elseif ($field === 'sourceSequence' && is_int($value)) {
            $this->sourceSequence = $value;
        } else {
            return false;
        }

        return true;
    }

    /**
     * The set fields in camelCase (null fields omitted), without the idempotency key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if ($this->{$field} !== null) {
                $out[$field] = $this->{$field};
            }
        }

        return $out;
    }

    /** One line, at most 160 characters. Defaults to the first line of the message. */
    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /** Plain text, 1–8192 bytes of UTF-8. Line breaks and tabs are allowed. */
    public function message(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /** Appends a line to the message text. */
    public function line(string $line): static
    {
        $this->message = $this->message === null || $this->message === '' ? $line : $this->message . "\n" . $line;

        return $this;
    }

    /**
     * The Honk scale: light (info), beep (success), loud (warning), long (error), blast
     * (critical). A Severity case, a horn name or a canonical value, any case; sent canonical.
     * long/error and blast/critical push at least as high priority.
     */
    public function severity(Severity|string $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    /** A light honk (info). */
    public function light(): static
    {
        return $this->severity(Severity::Info);
    }

    /** A beep-beep (success). */
    public function beep(): static
    {
        return $this->severity(Severity::Success);
    }

    /** A loud honk (warning). */
    public function loud(): static
    {
        return $this->severity(Severity::Warning);
    }

    /** A long honk (error). */
    public function long(): static
    {
        return $this->severity(Severity::Error);
    }

    /** A blast (critical). */
    public function blast(): static
    {
        return $this->severity(Severity::Critical);
    }

    /** Synonym of light(). */
    public function info(): static
    {
        return $this->light();
    }

    /** Synonym of beep(). */
    public function success(): static
    {
        return $this->beep();
    }

    /** Synonym of loud(). */
    public function warning(): static
    {
        return $this->loud();
    }

    /** Synonym of long(). */
    public function error(): static
    {
        return $this->long();
    }

    /** Synonym of blast(). */
    public function critical(): static
    {
        return $this->blast();
    }

    /** low, normal, high or urgent (urgent needs a key with allow_urgent). */
    public function priority(string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /** infrastructure, security, backups, deployments, payments, customers, sales, automation, personal or other. */
    public function category(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    /** At most 64 characters. */
    public function source(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    /** At most 32 characters. */
    public function environment(string $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    /** At most 64 characters. */
    public function channel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * At most 128 characters. Messages with the same key (per environment, source and channel)
     * form one group: one push, then calm updates. One key per customer request
     * ("requests/{$id}"); a shared key only for repeats of the same problem.
     */
    public function groupKey(string $groupKey): static
    {
        $this->groupKey = $groupKey;

        return $this;
    }

    /** event, problem or recovery (problem/recovery need a group key). */
    public function eventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    /** When it happened at the source: a DateTimeInterface (Carbon works) or an RFC 3339 string. */
    public function occurredAt(DateTimeInterface|string $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    /** An https link shown as "Open link" (no credentials). */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /** An https image the server fetches after ingestion (no credentials, no fragment). */
    public function imageUrl(string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * Merges metadata: at most 16 keys matching [A-Za-z0-9_.-]{1,64}; values are strings
     * (≤ 512 characters), numbers or booleans.
     *
     * @param array<string, string|int|float|bool> $metadata
     */
    public function metadata(array $metadata): static
    {
        $this->metadata = array_replace($this->metadata ?? [], $metadata);

        return $this;
    }

    public function meta(string $key, string|int|float|bool $value): static
    {
        return $this->metadata([$key => $value]);
    }

    /** Push lifetime, 60–86400 seconds (default 3600). */
    public function ttlSeconds(int $seconds): static
    {
        $this->ttlSeconds = $seconds;

        return $this;
    }

    /** Monotonic counter per source stream, for problem/recovery ordering. Needs a group key. */
    public function sourceSequence(int $sequence): static
    {
        $this->sourceSequence = $sequence;

        return $this;
    }

    /** A stable key for this event (1–128 printable ASCII characters), e.g. "request-4812". */
    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }
}
