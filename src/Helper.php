<?php

declare(strict_types=1);

namespace HonkMe;

/**
 * Builds the messages behind the helper methods (problem, recovery, light … blast), so every
 * entry point (Client, the Laravel facade, Honk::defer(), the fake) means the same thing.
 */
final class Helper
{
    /**
     * A problem for $groupKey; severity defaults to long (error).
     *
     * @param array<string, mixed> $options message fields in camelCase, plus 'idempotencyKey'
     */
    public static function problem(string $groupKey, ?string $title, string $message, array $options = []): Message
    {
        return self::build(['groupKey' => $groupKey, 'eventType' => 'problem'], ['severity' => $options['severity'] ?? 'error'] + $options, $title, $message);
    }

    /**
     * A recovery for $groupKey; severity defaults to beep (success).
     *
     * @param array<string, mixed> $options
     */
    public static function recovery(string $groupKey, ?string $title, string $message, array $options = []): Message
    {
        return self::build(['groupKey' => $groupKey, 'eventType' => 'recovery'], ['severity' => $options['severity'] ?? 'success'] + $options, $title, $message);
    }

    /**
     * A message with a fixed severity (horn name or canonical value).
     *
     * @param array<string, mixed> $options
     */
    public static function severity(Severity|string $severity, ?string $title, string $message, array $options = []): Message
    {
        return self::build(['severity' => $severity], $options, $title, $message);
    }

    /**
     * @param array<string, mixed> $forced
     * @param array<string, mixed> $options
     */
    private static function build(array $forced, array $options, ?string $title, string $message): Message
    {
        return Message::fromArray($forced + ['title' => $title, 'message' => $message] + $options);
    }
}
