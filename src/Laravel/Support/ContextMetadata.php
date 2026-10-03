<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Support;

use HonkMe\Message;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Context;

/**
 * Opt-in: copies allow-listed keys from Laravel's Context into the message metadata
 * (config honk.context). Only visible context is read, never hidden context. The message's own
 * metadata always wins, and the 16-key limit is respected.
 */
final class ContextMetadata
{
    /** Config is read on every message, so runtime changes (e.g. in tests) apply at once. */
    public function __construct(private readonly Repository $config)
    {
    }

    public function apply(Message $message): Message
    {
        $keys = $this->config->get('honk.context.keys', []);
        if ($this->config->get('honk.context.enabled') !== true || !is_array($keys) || $keys === []) {
            return $message;
        }
        $context = Context::all();
        $metadata = $message->metadata ?? [];
        foreach ($keys as $key) {
            if (!is_string($key) || !array_key_exists($key, $context)) {
                continue;
            }
            $name = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $key), 0, 64);
            $value = self::scalar($context[$key]);
            if ($name === '' || $value === null || array_key_exists($name, $metadata) || count($metadata) >= 16) {
                continue;
            }
            $metadata[$name] = $value;
        }
        if ($metadata === ($message->metadata ?? [])) {
            return $message;
        }
        $copy = clone $message;
        $copy->metadata = $metadata;

        return $copy;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', ' ', $value);

        return function_exists('mb_substr') ? mb_substr($value, 0, 512) : substr($value, 0, 512);
    }
}
