<?php

use Illuminate\Support\Str;

return [

    /*
    | Base URL of your Honk server and a project ingestion key (Project → Keys). The key is a
    | secret: keep it in .env, never in front-end code or Git.
    */
    'url' => env('HONK_URL'),
    'key' => env('HONK_KEY'),

    /*
    | Timeout of one HTTP attempt and the total budget of one send (retries and waits included),
    | in seconds. Network errors, 429 and 5xx are retried with the same Idempotency-Key, so a
    | retry never creates a duplicate.
    */
    'timeout' => (float) env('HONK_TIMEOUT', 5),
    'retries' => (int) env('HONK_RETRIES', 4),
    'deadline' => (float) env('HONK_DEADLINE', 30),

    /*
    | Applied to every message that leaves these fields unset. Grouping is per environment,
    | source and channel, so keep them stable.
    */
    'defaults' => [
        'source' => env('HONK_SOURCE', Str::slug((string) env('APP_NAME', 'laravel'))),
        'environment' => env('HONK_ENVIRONMENT', env('APP_ENV', 'production')),
        'channel' => env('HONK_CHANNEL'),
    ],

    /*
    | Honk::queue(): connection and queue (registered with Queue::route()); tries and backoff
    | (seconds) override the job's #[Tries(5)] / #[Backoff(10, 60, 300, 900)] when set.
    */
    'queue' => [
        'connection' => env('HONK_QUEUE_CONNECTION'),
        'queue' => env('HONK_QUEUE'),
        'tries' => null,
        'backoff' => null,
    ],

    /*
    | Opt-in: copy these keys from Laravel's Context (Context::add(...)) into the metadata of
    | every message. Only visible context is read, never hidden context. Do not list keys that
    | hold personal data (emails, names, IP addresses).
    */
    'context' => [
        'enabled' => (bool) env('HONK_CONTEXT', false),
        'keys' => ['request_id', 'trace_id', 'route'],
    ],

    /*
    | $exceptions->report(Honk::reportable()) in bootstrap/app.php: one long honk (a problem,
    | grouped per exception class and location) at most every `throttle` seconds per group.
    | mode: defer (after the response), sync, or queue.
    */
    'exceptions' => [
        'enabled' => (bool) env('HONK_EXCEPTIONS', true),
        'severity' => 'long',
        'channel' => 'exceptions',
        'throttle' => 300,
        'mode' => 'defer',
        'ignore' => [],
    ],

];
