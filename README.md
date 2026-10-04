# honk-me/honk-me

[![CI](https://github.com/honk-me/honk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/honk-me/honk-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/honk-me/honk-me)](https://packagist.org/packages/honk-me/honk-me)

Official PHP client for [Honk](https://honk-me.app), the inbox that turns events from your
apps, scripts, cron jobs and CI into calm, grouped push notifications on your phone.
Framework-agnostic, with a first-class Laravel 13 integration.

- PHP 8.3+. ext-curl with keep-alive by default, or any PSR-18 client.
- Laravel 13: auto-discovered provider, `Honk` facade, `Honk::defer()` / `Honk::queue()`,
  the `honk` notification channel, `Honk::fake()`, exception reporting, scheduler hooks,
  `php artisan honk:install` / `honk:test` and a `php artisan about` section.
- Retries with backoff, `Retry-After`, a total deadline and an idempotency key on every send,
  so a retry never creates a duplicate.

The ingestion key (`honk_…`) is a secret: keep it in `.env`, never in front-end code or Git.

## Install

```sh
composer require honk-me/honk-me
```

Create a project and an ingestion key at [honk-me.app](https://honk-me.app). Its
*Integrations* page generates ready-to-paste code for plain PHP and Laravel.

## Quick start

```php
use HonkMe\Client;

$honk = new Client(url: getenv('HONK_URL'), key: getenv('HONK_KEY'));
$honk->beep('Backup finished', 'nightly pg_dump took 42 s');
```

`Client::fromEnv()` reads `HONK_URL`, `HONK_KEY` and the optional `HONK_SOURCE`,
`HONK_ENVIRONMENT` and `HONK_CHANNEL` defaults.

## The Honk scale

Every severity has a horn name. Use either; the SDK always sends the canonical value.

| Horn | Severity | Client / facade | Builder | Enum |
|---|---|---|---|---|
| light honk | `light` (info) | `light($title, $message, $options)` | `->light()` | `Severity::Light` |
| beep-beep | `beep` (success) | `beep(…)` | `->beep()` | `Severity::Beep` |
| loud honk | `loud` (warning) | `loud(…)` | `->loud()` | `Severity::Loud` |
| long honk | `long` (error) | `long(…)` | `->long()` | `Severity::Long` |
| blast | `blast` (critical) | `blast(…)` | `->blast()` | `Severity::Blast` |

`->severity('loud')`, `'LOUD'`, `'warning'` and `Severity::Loud` (which *is*
`Severity::Warning`) are the same event, also for idempotency. `long` and `blast` push at
least as `high` priority. `info()`, `success()`, `warning()`, `error()` and `critical()`
remain as synonyms; `Severity::parse('Blast')` returns `Severity::Critical`.

## Laravel 13

```sh
composer require honk-me/honk-me
php artisan honk:install      # publishes config/honk.php, adds HONK_URL / HONK_KEY to .env.example
```

Put your server and a project ingestion key in `.env` (never commit it), then check the
connection:

```dotenv
HONK_URL=https://honk.example.com
HONK_KEY=honk_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

```sh
php artisan honk:test         # sends a light honk and prints the message id
php artisan about --only=honk # URL, key prefix (never the secret), queue, Context metadata
```

`source` defaults to your `APP_NAME` (slugged) and `environment` to `APP_ENV`. The package is
for Laravel 13 only (PHP 8.3+); Composer refuses older Laravel versions.

### Recipe: notify me when a customer asks for something

**1. A notification** on the `honk` channel. `ShouldQueue` keeps the customer's request fast;
Laravel 13's queue attributes set the retries:

```php
// app/Notifications/CustomerRequested.php
namespace App\Notifications;

use App\Models\CustomerRequest;
use HonkMe\Laravel\Notifications\HonkMessage;
use HonkMe\Laravel\Notifications\ToHonk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;

#[Tries(5)]
#[Backoff(10, 60, 300, 900)]
class CustomerRequested extends Notification implements ShouldQueue, ToHonk
{
    use Queueable;

    public function __construct(public CustomerRequest $request)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['honk']; // add 'mail', 'database', … as you like
    }

    public function toHonk(object $notifiable): HonkMessage
    {
        $r = $this->request;

        return HonkMessage::create()
            ->title(Str::limit("New request: {$r->subject}", 150))
            ->line("{$r->name} ({$r->company})")
            ->line(Str::limit($r->body, 2000))       // message: ≤ 8192 bytes
            ->light()                                // a light honk (info)
            ->category('customers')
            ->groupKey("requests/{$r->id}")          // one group per request
            ->occurredAt($r->created_at)
            ->url(route('admin.requests.show', $r))  // https only, shown as "Open link"
            ->meta('request_id', (string) $r->id);
    }
}
```

**2. Send it** where the request is stored:

```php
$request = CustomerRequest::create($validated);
$admin->notify(new CustomerRequested($request));            // a User with the Notifiable trait
Notification::route('honk', null)->notify(new CustomerRequested($request)); // or without a user
```

The phone buzzes once per request, follow-ups for the same request update the same group, and
queue retries never create duplicates: the idempotency key is `notification-{id}`, which
Laravel keeps across retries. `examples/laravel-app` in the Honk repository is this recipe as a
complete Laravel 13 + Inertia + Vue + Wayfinder app.

- Keep `toHonk()` deterministic (no `now()`; use `$r->created_at`): a queued retry must send the
  same payload, otherwise Honk answers `409 idempotency_conflict`.
- Links and images must be `https://`. With `APP_URL=http://localhost` in development, leave
  `->url()` / `->imageUrl()` out or guard them with `str_starts_with($link, 'https://')`.
- Routing: without `routeNotificationForHonk()` the configured key is used. Return another
  ingestion key, an array of config overrides (`['key' => …, 'defaults' => [...]]`), a
  `HonkMe\Client`, or `false` to skip Honk for that notifiable.

### Send now, after the response, or from the queue

```php
use HonkMe\Laravel\Facades\Honk;
use HonkMe\Message;

Honk::defer()->beep('New order', "{$order->email} paid {$order->total} €");   // after the response
Honk::queue(Message::make('Imported 1 204 rows')->beep()->channel('imports'));   // a queued job
Honk::loud('Disk 91%', '/var on app-01', ['groupKey' => 'disk/app-01/var']);     // right now
```

| | Runs | Use it for |
|---|---|---|
| `Honk::defer()` | in this process, after the HTTP response is sent (Laravel's `defer()`; after the command or job in the console and on queues) | web requests where a lost honk is acceptable if the process dies; no worker needed. Skipped when the request fails (4xx/5xx) unless `Honk::defer(always: true)` |
| `Honk::queue()` | in a queue worker (`SendHonkMessage`, `#[Tries(5)]`, `#[Backoff(10, 60, 300, 900)]`) | anything that must arrive: survives restarts and retries with the same key; a daily quota releases the job until the reset |
| `Honk::send()`, `Honk::light()` … | right now, retries included (up to `honk.deadline`) | jobs, commands, the scheduler |

`defer()` and `queue()` validate the message immediately, so mistakes surface in your code,
not later. `queue()` returns the job; the connection and queue come from
`HONK_QUEUE_CONNECTION` / `HONK_QUEUE` (registered with Laravel 13's `Queue::route()`).

### Testing: `Honk::fake()`

```php
use HonkMe\Laravel\Facades\Honk;
use HonkMe\Message;
use HonkMe\Severity;

Honk::fake();

$this->post(route('quote.store'), $data);

Honk::assertSent(fn (Message $m) => $m->groupKey === 'requests/1' && $m->severity === Severity::Light);
Honk::assertSentTimes(1);
Honk::assertNotSent(fn (Message $m) => $m->severity === Severity::Blast);
Honk::assertQueued(fn (Message $m, string $idempotencyKey) => $m->channel === 'imports');
Honk::assertNothingSent(); // also assertNothingQueued(), assertNothingOutgoing()
```

The fake records `send()`, the helpers, `defer()` and notification-channel sends as *sent*, and
`queue()` as *queued*; nothing touches the network. It validates like the real client (an
invalid message still throws), applies the defaults and Context metadata, and records the
severity as a `Severity` case whatever spelling was used. Callbacks receive the message and its
idempotency key. With `Notification::fake()` instead, `toHonk()` is never called.

### Exceptions

One line in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->report(Honk::reportable());
})
```

Every reported exception becomes a long honk: a *problem* whose `group_key` is
`exceptions/<Class>@<file>:<line>`, so repeats of one failure are a single incident. At most
one honk per group per 5 minutes (`honk.exceptions.throttle`, cache-backed), sent after the
response (`honk.exceptions.mode`: `defer`, `sync` or `queue`). Pick another severity or window
per app: `Honk::reportable(severity: 'blast', throttleSeconds: 60)`; list classes to skip in
`honk.exceptions.ignore`. It never honks about Honk's own failures, does nothing while
`HONK_URL` / `HONK_KEY` are missing (local development, CI), and never throws. Exception
messages are sent as they are (truncated): do not put personal data in them.

### Scheduler

```php
// routes/console.php
Schedule::command('backup:run')->daily()->honkOnFailure();          // problem on failure, recovery once it works again
Schedule::command('reports:send')->hourly()->honkOnSuccess();       // a beep after every successful run
```

`honkOnFailure(?string $groupKey = null, string $severity = 'long')` sends a problem with the
exit code and the output tail, grouped per task (`schedule/<command or description>`), and a
recovery the next time the task succeeds, only after a failure: the failing state lives in the
cache, so a healthy task never pings (a recovery without an open problem would still notify).

### Context (opt-in)

With `HONK_CONTEXT=true`, the keys listed in `honk.context.keys` (default `request_id`,
`trace_id`, `route`) are copied from Laravel's `Context` into each message's `metadata`. Only
visible context is read, never hidden context; the message's own metadata wins; the 16-key
limit is respected. Nothing is copied by default, and the list should never contain personal
data (emails, names, IP addresses). Add the values in a middleware, for example:

```php
Context::add('trace_id', (string) Str::uuid());
Context::add('route', $request->route()?->getName());
```

Context also travels with queued jobs, so queued honks carry the values of the request that
dispatched them.

### Events and container

- `HonkMe\Laravel\Events\HonkSent` (message, `Accepted`, idempotency key, `via`) and
  `HonkFailed` (message, exception, idempotency key, `via`) fire for every send: `send`,
  `defer`, `queue` or `notification`.
- `HonkManager` (the facade root) is a `#[Singleton]` that receives `#[Config('honk')]`; inject
  it, or `HonkMe\Client`, anywhere. Config is read when Honk is first used.

## Grouping in three lines

Messages with the same `groupKey` (per project, environment, source and channel) form one
group: the first one pushes, repeats update it calmly instead of buzzing again. Use one key
per customer request (`requests/{$id}`), and a shared key only for repeats of the same problem
(`queue/failed-jobs`). `problem`/`recovery` pairs need a `groupKey`.

## Sending

```php
$client->send(Message|array $message, ?string $idempotencyKey = null): Accepted // id, duplicate, receivedAt
```

| Field (builder method) | Notes |
|---|---|
| `message` | **required**, 1–8192 bytes UTF-8, line breaks allowed (`->line()` appends one) |
| `title` | ≤ 160 chars, one line; default: first line of the message |
| `severity` (`->light()` … `->blast()`) | `light` `beep` `loud` `long` `blast`, a `Severity` case, or the canonical names, any case; default `light`; `long`/`blast` push at least as `high` |
| `priority` | `low` `normal` `high` `urgent` (`urgent` needs a key with *allow urgent*) |
| `category` | `infrastructure` `security` `backups` `deployments` `payments` `customers` `sales` `automation` `personal` `other` |
| `source` / `environment` / `channel` | ≤ 64 / 32 / 64 chars; default `api` / `default` / `general` or the client defaults |
| `groupKey` | ≤ 128 chars |
| `eventType` | `event` `problem` `recovery` (`recovery` needs `groupKey`) |
| `occurredAt` | `DateTimeInterface` (Carbon works) or RFC 3339 string |
| `url` | `https://` only, no credentials |
| `imageUrl` | `https://` only, no credentials or `#fragment`; fetched by the server afterwards |
| `metadata` (`->meta($k, $v)`) | ≤ 16 keys `[A-Za-z0-9_.-]{1,64}`; string (≤ 512 chars), int, float or bool values |
| `ttlSeconds` | push lifetime 60–86400, default 3600 |
| `sourceSequence` | 0 … 2^53-1, needs `groupKey` |
| `idempotencyKey` | 1–128 printable ASCII characters |

Arrays use the same camelCase keys (`['message' => …, 'groupKey' => …]`); snake_case keys
are rejected with a hint. Null and empty optional fields are omitted. A returned `Accepted`
means Honk **durably stored** the message (`202`), not that a push was delivered or read.

Helpers (`$options` takes any field in camelCase plus `idempotencyKey`):

```php
$client->loud('Disk 91%', '/var on app-01', ['groupKey' => 'disk/app-01/var']);
$client->light($title, $message, $options);  $client->beep(...);  $client->long(...);  $client->blast(...);
$client->problem('db/backup', 'Backup failed', 'pg_dump exited with 1');   // a long honk by default
$client->recovery('db/backup', 'Backup OK', 'pg_dump finished in 41 s');   // a beep by default
```

### Options

```php
new Client(
    url: 'https://honk.example.com',
    key: 'honk_…',
    timeoutMs: 5000,       // per attempt
    retries: 4,            // after the first attempt
    deadlineMs: 30000,     // total, waits included
    defaults: ['source' => 'billing', 'environment' => 'production', 'channel' => 'payments'],
    validate: true,        // local checks (the server always validates)
    httpClient: $psr18,    // optional PSR-18 client; with requestFactory/streamFactory (auto with Guzzle)
);
```

With a PSR-18 client, configure its own timeout and disable redirects
(Guzzle: `new GuzzleHttp\Client(['timeout' => 5, 'allow_redirects' => false])`); the SDK
still enforces the total deadline.

## Retries and idempotency, guaranteed

- Every send carries an `Idempotency-Key`: yours, or a fresh UUIDv7. **The same key is reused
  on every retry.** Within 24 h Honk answers a replay with the original id and
  `duplicate: true`, so a lost response never creates a second message.
- Only network errors, timeouts, `429` and `5xx` are retried, with exponential backoff and
  full jitter (`random(0, min(8 s, 0.5 s·2ⁿ))`), never sooner than the server's `Retry-After`.
- Everything stops at `deadlineMs`: if the next wait would cross it (for example a daily quota
  that resets at midnight), the exception is thrown at once with `retryAfter`.
- `4xx` other than `429` are never retried: fix the request instead.
- Short per-attempt timeouts, one keep-alive connection per client (reuse the client; Laravel
  keeps one per worker).
- Use a stable key of your own (`request-4812`) to stay duplicate-free across processes and job
  retries. Reusing a key with a *different* payload is a conflict.

## Errors

All exceptions extend `HonkMe\Exception\HonkException` and expose `status`, `errorCode`,
`requestId`, `idempotencyKey`, `attempts`, `retryAfter` and `isRetryable()`.

| Exception | When | What to do |
|---|---|---|
| `ValidationException` | rejected locally (`local === true`) or `400`/`413`/`415`/`422`; `fields` lists every problem | fix the message |
| `AuthException` | `401 invalid_key`, `403 priority_not_allowed`, `project_suspended`, `workspace_suspended` | fix the key or the priority |
| `QuotaException` | `429 quota_exceeded` (daily, resets at UTC midnight) or `rate_limited`, after retries | retry after `retryAfter` seconds |
| `ConflictException` | `409 idempotency_conflict`: same key, different payload | use a new key or the original payload |
| `NetworkException` | unreachable on every attempt | retry later, same key |
| `TimeoutException` (a `NetworkException`) | attempts timed out; the message may or may not be stored | retry later, same key |
| `ServerException` | `5xx` on every attempt | retry later, same key |

```php
use HonkMe\Exception\HonkException;
use HonkMe\Exception\ValidationException;

try {
    $honk->send($message, "order-{$order->id}-failed");
} catch (ValidationException $e) {
    report($e); // a bug: $e->fields says what to fix
} catch (HonkException $e) {
    if (!$e->isRetryable()) {
        throw $e;
    }
    // retry later with $e->idempotencyKey, after $e->retryAfter seconds if set
}
```

## Development

```sh
composer install
composer test                 # unit tests (scriptable mock server) + Laravel 13 tests (Orchestra Testbench 11)
composer analyse              # PHPStan level max with Larastan
HONK_URL=… HONK_KEY=… composer test:integration   # against a real server (use a test project's key)
```

The version lives in `HonkMe\Client::VERSION` (also the User-Agent). Releases: push a tag
`vX.Y.Z` matching it; Packagist picks the tag up (see `CHANGELOG.md`).

## Links

- [honk-me.app](https://honk-me.app): the Honk inbox (web, iPhone).
- Other SDKs: [Node.js](https://github.com/honk-me/honk-node),
  [Go + CLI](https://github.com/honk-me/honk-go), [Swift](https://github.com/honk-me/honk-swift),
  [Kotlin / Java](https://github.com/honk-me/honk-kotlin).

MIT License.
