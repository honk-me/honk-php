<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Facades;

use Closure;
use HonkMe\Laravel\Exceptions\ExceptionHonker;
use HonkMe\Laravel\HonkManager;
use HonkMe\Laravel\Testing\HonkFake;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static \HonkMe\Accepted send(\HonkMe\Message|array<string, mixed> $message, ?string $idempotencyKey = null)
 * @method static \HonkMe\Laravel\Jobs\SendHonkMessage queue(\HonkMe\Message|array<string, mixed> $message, ?string $idempotencyKey = null, \DateTimeInterface|\DateInterval|int|null $delay = null)
 * @method static \HonkMe\Laravel\PendingDeferredHonk defer(bool $always = false)
 * @method static \HonkMe\Accepted problem(string $groupKey, ?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted recovery(string $groupKey, ?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted light(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted beep(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted loud(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted long(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted blast(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted info(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted success(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted warning(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted error(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Accepted critical(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \HonkMe\Client client()
 * @method static \HonkMe\Client clientFor(mixed $route)
 * @method static list<\HonkMe\Message> sent(?callable $callback = null)
 * @method static list<\HonkMe\Message> queued(?callable $callback = null)
 * @method static void assertSent(?callable $callback = null)
 * @method static void assertSentTimes(int $times, ?callable $callback = null)
 * @method static void assertNotSent(callable $callback)
 * @method static void assertNothingSent()
 * @method static void assertQueued(callable|int|null $callback = null)
 * @method static void assertNothingQueued()
 * @method static void assertNothingOutgoing()
 *
 * @see HonkManager
 * @see HonkFake
 */
final class Honk extends Facade
{
    /** Replaces Honk with a fake that records messages instead of sending them. */
    public static function fake(): HonkFake
    {
        $fake = Container::getInstance()->make(HonkFake::class);
        static::swap($fake);

        return $fake;
    }

    /**
     * A report callback for bootstrap/app.php: honks a long honk (a problem grouped per
     * exception class and location, throttled) for every reported exception.
     *
     *     ->withExceptions(function (Exceptions $exceptions): void {
     *         $exceptions->report(Honk::reportable());
     *     })
     *
     * Resolved lazily, so it is safe to call while the exception handler is being configured.
     *
     * @param string|null $severity        horn or canonical name (default honk.exceptions.severity, "long")
     * @param int|null    $throttleSeconds one honk per exception class + location per window (default honk.exceptions.throttle)
     *
     * @return Closure(Throwable): void
     */
    public static function reportable(?string $severity = null, ?int $throttleSeconds = null): Closure
    {
        return static function (Throwable $e) use ($severity, $throttleSeconds): void {
            Container::getInstance()->make(ExceptionHonker::class)->report($e, $severity, $throttleSeconds);
        };
    }

    protected static function getFacadeAccessor(): string
    {
        return HonkManager::class;
    }
}
