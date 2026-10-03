<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Scheduling;

use HonkMe\Laravel\HonkManager;
use HonkMe\Message;
use HonkMe\Severity;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scheduler hooks behind the Event macros:
 *
 *     Schedule::command('backup:run')->daily()->honkOnFailure();
 *     Schedule::command('reports:send')->hourly()->honkOnSuccess();
 *
 * honkOnFailure() sends a problem when the task fails and a recovery the next time it succeeds
 * (only after a failure: the failing state is kept in the cache, so a healthy task never pings).
 */
final class ScheduleHonk
{
    public static function register(): void
    {
        Event::macro('honkOnFailure', function (?string $groupKey = null, string $severity = 'long') {
            /** @var Event $this */
            $event = $this;
            $key = $groupKey ?? ScheduleHonk::groupKeyFor($event);

            return $event
                ->onFailure(function (Stringable $output) use ($event, $key, $severity): void {
                    ScheduleHonk::failed($event, $key, $severity, (string) $output);
                })
                ->onSuccess(function () use ($event, $key): void {
                    ScheduleHonk::succeeded($event, $key);
                });
        });

        Event::macro('honkOnSuccess', function (?string $title = null, string $severity = 'beep') {
            /** @var Event $this */
            $event = $this;

            return $event->onSuccess(function () use ($event, $title, $severity): void {
                ScheduleHonk::send(Message::make('Finished: ' . ScheduleHonk::summary($event))
                    ->title($title ?? Str::limit('Done: ' . ScheduleHonk::summary($event), 155))
                    ->severity(Severity::tryParse($severity) ?? Severity::Success)
                    ->groupKey(ScheduleHonk::groupKeyFor($event)));
            });
        });
    }

    public static function failed(Event $event, string $groupKey, string $severity, string $output): void
    {
        $summary = self::summary($event);
        $message = Message::make("Scheduled task failed: {$summary}")
            ->title(Str::limit("Task failed: {$summary}", 155))
            ->line('Exit code: ' . ($event->exitCode ?? 'unknown'));
        $tail = trim((string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', ' ', $output));
        if ($tail !== '') {
            $message->line('')->line('…' . mb_substr($tail, -1500));
        }
        $message->severity(Severity::tryParse($severity) ?? Severity::Error)->eventType('problem')->groupKey($groupKey);

        self::cache()->forever(self::stateKey($groupKey), true);
        self::send($message);
    }

    public static function succeeded(Event $event, string $groupKey): void
    {
        if (self::cache()->pull(self::stateKey($groupKey)) !== true) {
            return; // recover only after a failure
        }
        $summary = self::summary($event);
        self::send(Message::make("Scheduled task succeeded again: {$summary}")
            ->title(Str::limit("Task recovered: {$summary}", 155))
            ->beep()
            ->eventType('recovery')
            ->groupKey($groupKey));
    }

    /** "schedule/<command or description>", stable across runs. */
    public static function groupKeyFor(Event $event): string
    {
        return Str::limit('schedule/' . self::summary($event), 128, '');
    }

    public static function summary(Event $event): string
    {
        if (is_string($event->description) && $event->description !== '') {
            return Str::squish($event->description);
        }
        $command = (string) ($event->command ?? '');
        // "'/usr/bin/php8.4' 'artisan' backup:run --only-db" → "backup:run --only-db"
        if (preg_match("/['\"]?artisan['\"]?\\s+(.+)$/", $command, $m) === 1) {
            $command = $m[1];
        }
        $command = Str::squish(str_replace(["'", '"'], '', $command));

        return $command !== '' ? $command : $event->getSummaryForDisplay();
    }

    public static function send(Message $message): void
    {
        $container = \Illuminate\Container\Container::getInstance();
        try {
            $container->make(HonkManager::class)->send($message);
        } catch (Throwable $e) {
            // The scheduler must keep running; HonkFailed was dispatched for listeners.
            $container->make(LoggerInterface::class)->warning('Honk scheduler hook failed: ' . $e->getMessage());
        }
    }

    private static function stateKey(string $groupKey): string
    {
        return 'honk:schedule:failing:' . sha1($groupKey);
    }

    private static function cache(): Cache
    {
        /** @var Container $container */
        $container = \Illuminate\Container\Container::getInstance();

        return $container->make(Cache::class);
    }
}
