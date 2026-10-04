# Changelog

All notable changes to `honk-me/honk-me` (Composer) are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-04

### Added
- Framework-agnostic `HonkMe\Client` for `POST /v1/messages` (PHP 8.3+), using ext-curl with
  keep-alive by default or any PSR-18 client with PSR-17 factories.
- Fluent `HonkMe\Message` builder with every field of the v1 ingestion API (including
  `imageUrl`); arrays with camelCase keys are accepted too.
- Automatic UUIDv7 `Idempotency-Key` (or your own), reused on every retry.
- Retries for network errors, timeouts, 429 and 5xx with exponential backoff, full jitter,
  `Retry-After` and a total deadline.
- Typed exceptions: `ValidationException`, `AuthException`, `QuotaException`,
  `ConflictException`, `NetworkException`, `TimeoutException`, `ServerException`.
- Local validation of limits, enums and https-only URLs, with every invalid field reported.
- The Honk scale: `HonkMe\Severity` enum with horn aliases (`Severity::Loud` is
  `Severity::Warning`), `Severity::parse()`, case-insensitive aliases always sent canonical,
  and `light()`, `beep()`, `loud()`, `long()`, `blast()` on the builder, client and facade;
  `problem`, `recovery` and the `info` … `critical` synonyms; `Client::fromEnv()`.
- Laravel 13 integration:
  - auto-discovered `HonkServiceProvider`, publishable `config/honk.php` (tag `honk-config`),
    `php artisan honk:install`, `php artisan honk:test` and a Honk section in
    `php artisan about`;
  - `Honk` facade on a `#[Singleton]` `HonkManager` configured with `#[Config('honk')]`;
  - `Honk::defer()` (after the response, via `defer()`), `Honk::queue()` (`SendHonkMessage` job
    with `#[Tries]` / `#[Backoff]`, routed with `Queue::route()`);
  - `honk` notification channel (`HonkChannel`, `HonkMessage`, the `ToHonk` contract),
    idempotent across queued retries;
  - `Honk::fake()` with `assertSent`, `assertSentTimes`, `assertNotSent`, `assertNothingSent`,
    `assertQueued`, `assertNothingQueued`, `assertNothingOutgoing`;
  - `Honk::reportable()` for `$exceptions->report(...)`: grouped, throttled problems per
    exception class and location;
  - `->honkOnFailure()` / `->honkOnSuccess()` on scheduled tasks;
  - opt-in Context metadata (allow-list, never hidden context);
  - `HonkSent` / `HonkFailed` events.
