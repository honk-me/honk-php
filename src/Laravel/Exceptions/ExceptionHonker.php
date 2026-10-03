<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Exceptions;

use HonkMe\Exception\HonkException;
use HonkMe\Laravel\HonkManager;
use HonkMe\Laravel\Testing\HonkFake;
use HonkMe\Message;
use HonkMe\Severity;
use Illuminate\Cache\RateLimiter;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns reported exceptions into Honk problems (see Honk::reportable()).
 *
 * - group_key "exceptions/<Class>@<file>:<line>": repeats of the same failure form one incident;
 * - at most one honk per group key per throttle window (the cache-backed RateLimiter), so a
 *   burst never spends the daily quota;
 * - sent after the response by default (Laravel's defer(), always: true) so error pages stay fast;
 * - never honks about Honk's own failures, does nothing while Honk is not configured (no
 *   HONK_URL / HONK_KEY, unless Honk::fake() is active), and never throws: reporting must not
 *   break reporting.
 */
final class ExceptionHonker
{
    /** Re-entrancy guard: an exception raised while honking is never honked about. */
    private static bool $reporting = false;

    /**
     * @param array<string, mixed>|null $config honk.exceptions
     */
    public function __construct(
        private readonly Container $container,
        private readonly RateLimiter $limiter,
        #[Config('honk.exceptions')] private readonly ?array $config = null,
        #[Config('honk.url')] private readonly mixed $url = null,
        #[Config('honk.key')] private readonly mixed $key = null,
    ) {
    }

    public function report(Throwable $e, ?string $severity = null, ?int $throttleSeconds = null): void
    {
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;
        try {
            if ($e instanceof HonkException || ($this->config['enabled'] ?? true) === false || $this->ignored($e)) {
                return;
            }
            /** @var HonkManager $honk */
            $honk = $this->container->make(HonkManager::class);
            if (!$honk instanceof HonkFake && (!is_string($this->url) || $this->url === '' || !is_string($this->key) || $this->key === '')) {
                return; // not configured (e.g. local development or CI without HONK_URL / HONK_KEY)
            }
            $groupKey = self::groupKey($e, $this->basePath());
            $window = $throttleSeconds ?? (is_numeric($this->config['throttle'] ?? null) ? (int) $this->config['throttle'] : 300);
            if ($window > 0 && !$this->limiter->attempt('honk:exception:' . sha1($groupKey), 1, static fn () => true, $window)) {
                return;
            }
            $message = $this->message($e, $groupKey, $severity ?? (is_string($this->config['severity'] ?? null) ? $this->config['severity'] : 'long'));
            match ($this->config['mode'] ?? 'defer') {
                'sync' => $honk->send($message),
                'queue' => $honk->queue($message),
                default => $honk->defer(always: true)->send($message),
            };
        } catch (Throwable $failure) {
            $this->log($failure);
        } finally {
            self::$reporting = false;
        }
    }

    public static function groupKey(Throwable $e, string $basePath = ''): string
    {
        $file = $e->getFile();
        if ($basePath !== '' && str_starts_with($file, $basePath)) {
            $file = ltrim(substr($file, strlen($basePath)), '/\\');
        }
        $class = self::className($e);
        $key = 'exceptions/' . $class . '@' . $file . ':' . $e->getLine();
        if (strlen($key) <= 128) {
            return $key;
        }

        return substr('exceptions/' . class_basename($class) . '@' . basename($file) . ':' . $e->getLine(), 0, 115) . '#' . substr(sha1($key), 0, 12);
    }

    /** The class name, without the NUL byte and path PHP appends to anonymous classes. */
    public static function className(Throwable $e): string
    {
        $class = get_class($e);
        $nul = strpos($class, "\0");

        return $nul === false ? $class : substr($class, 0, $nul);
    }

    private function message(Throwable $e, string $groupKey, string $severity): Message
    {
        $class = self::className($e);
        $text = self::clean($e->getMessage());
        $location = $groupKey === '' ? '' : substr($groupKey, strpos($groupKey, '@') + 1);
        $title = Str::limit(class_basename($class) . ($text !== '' ? ': ' . Str::squish($text) : ''), 155);

        return Message::make()
            ->title($title)
            ->line($class . ($text !== '' ? ': ' . Str::limit($text, 2000) : ''))
            ->line('at ' . $location)
            ->severity(Severity::tryParse($severity) ?? Severity::Error)
            ->eventType('problem')
            ->groupKey($groupKey)
            ->channel(is_string($this->config['channel'] ?? null) && $this->config['channel'] !== '' ? $this->config['channel'] : 'exceptions')
            ->metadata(['exception' => Str::limit($class, 500), 'line' => $e->getLine()]);
    }

    private function ignored(Throwable $e): bool
    {
        foreach ((array) ($this->config['ignore'] ?? []) as $class) {
            if (is_string($class) && $e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function basePath(): string
    {
        return function_exists('base_path') ? base_path() : '';
    }

    /** Exception messages may carry control characters; the API rejects them. */
    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', ' ', $text));
    }

    private function log(Throwable $failure): void
    {
        try {
            $this->container->make(LoggerInterface::class)->warning('Honk could not report an exception: ' . $failure->getMessage());
        } catch (Throwable) {
            // never let reporting fail
        }
    }
}
