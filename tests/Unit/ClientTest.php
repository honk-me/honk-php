<?php

declare(strict_types=1);

namespace HonkMe\Tests\Unit;

use DateTimeImmutable;
use HonkMe\Accepted;
use HonkMe\Client;
use HonkMe\Exception\AuthException;
use HonkMe\Exception\ConflictException;
use HonkMe\Exception\HonkException;
use HonkMe\Exception\NetworkException;
use HonkMe\Exception\QuotaException;
use HonkMe\Exception\ServerException;
use HonkMe\Exception\TimeoutException;
use HonkMe\Exception\ValidationException;
use HonkMe\Message;
use HonkMe\Tests\Support\MockServer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public const KEY = 'honk_ab12cd34ef56_0123456789abcdefghijABCDEFGHIJ0123';
    private const UUIDV7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private MockServer $server;

    protected function setUp(): void
    {
        $this->server = MockServer::get();
        $this->server->script([MockServer::accepted()]);
    }

    /** @param array<string, mixed> $args */
    private function client(array $args = []): Client
    {
        return new Client(...$args + ['url' => $this->server->url, 'key' => self::KEY, 'backoffBaseMs' => 1, 'backoffMaxMs' => 5]);
    }

    public function testSerialisesEveryFieldWithOpenApiNames(): void
    {
        $res = $this->client()->send(Message::make('Billing API could not connect to Redis after 3 attempts.')
            ->title('Redis connection failed')
            ->error()
            ->priority('high')
            ->category('infrastructure')
            ->source('billing-api')
            ->environment('production')
            ->channel('infrastructure')
            ->groupKey('billing/redis/connectivity')
            ->eventType('problem')
            ->occurredAt(new DateTimeImmutable('2026-10-02T00:10:00+03:00'))
            ->url('https://example.com/incidents/redis')
            ->imageUrl('https://cdn.example.com/a.jpg')
            ->metadata(['host' => 'app-01', 'attempts' => 3])
            ->meta('retried', true)
            ->ttlSeconds(3600)
            ->sourceSequence(42));

        $this->assertInstanceOf(Accepted::class, $res);
        $this->assertSame('msg_01k6h3w4z5x6y7z8a9b0c1d2e3', $res->id);
        $this->assertFalse($res->duplicate);
        $this->assertSame('2026-10-02T21:10:00.123Z', $res->toArray()['receivedAt']);

        [$r] = $this->server->requests();
        $this->assertSame('POST', $r['method']);
        $this->assertSame('/v1/messages', $r['path']);
        $this->assertSame('Bearer ' . self::KEY, $r['headers']['authorization']);
        $this->assertSame('application/json', $r['headers']['content-type']);
        $this->assertSame('honk-me-php/' . Client::VERSION, $r['headers']['user-agent']);
        $this->assertMatchesRegularExpression(self::UUIDV7, $r['headers']['idempotency-key']);
        $this->assertArrayNotHasKey('expect', $r['headers']);
        $this->assertSame([
            'title' => 'Redis connection failed',
            'message' => 'Billing API could not connect to Redis after 3 attempts.',
            'severity' => 'error',
            'priority' => 'high',
            'category' => 'infrastructure',
            'source' => 'billing-api',
            'environment' => 'production',
            'channel' => 'infrastructure',
            'group_key' => 'billing/redis/connectivity',
            'event_type' => 'problem',
            'occurred_at' => '2026-10-01T21:10:00.000Z',
            'url' => 'https://example.com/incidents/redis',
            'image_url' => 'https://cdn.example.com/a.jpg',
            'metadata' => ['host' => 'app-01', 'attempts' => 3, 'retried' => true],
            'ttl_seconds' => 3600,
            'source_sequence' => 42,
        ], json_decode($r['body'], true));
    }

    public function testArraysUseCamelCaseAndOmitEmptyFields(): void
    {
        $this->client()->send(['message' => 'Backup finished in 42s', 'title' => null, 'channel' => '', 'groupKey' => 'db/backup']);
        $this->assertSame('{"message":"Backup finished in 42s","group_key":"db/backup"}', $this->server->requests()[0]['body']);
    }

    public function testActionsAreSentInOrderAndEmptyActionsAreOmitted(): void
    {
        $honk = $this->client();
        $honk->send(Message::make('Ana asked for a quote')
            ->action('Reply', 'mailto:ana@acme.example?subject=Your%20quote')
            ->action('Call Ana', 'tel:+15550134')
            ->action('Open request', 'https://shop.example.com/admin/requests/4812'));
        $honk->send(['message' => 'x', 'actions' => []]);
        $honk->send(['message' => 'x', 'actions' => null]);
        $honk->loud('Disk 91%', '/var on app-01', ['actions' => [['title' => 'Text on-call', 'url' => 'sms:+15550134?body=Disk%2091%25']]]);
        $r = $this->server->requests();
        $this->assertSame(
            '{"message":"Ana asked for a quote","actions":[{"title":"Reply","url":"mailto:ana@acme.example?subject=Your%20quote"},{"title":"Call Ana","url":"tel:+15550134"},{"title":"Open request","url":"https://shop.example.com/admin/requests/4812"}]}',
            $r[0]['body'],
        );
        $this->assertSame('{"message":"x"}', $r[1]['body']);
        $this->assertSame('{"message":"x"}', $r[2]['body']);
        $this->assertSame([['title' => 'Text on-call', 'url' => 'sms:+15550134?body=Disk%2091%25']], json_decode($r[3]['body'], true)['actions']);
    }

    public function testDefaultsFillUnsetFields(): void
    {
        $honk = $this->client(['defaults' => ['source' => 'laravel', 'environment' => 'production', 'channel' => null]]);
        $honk->send(['message' => 'a']);
        $honk->send(['message' => 'b', 'source' => 'cron', 'channel' => 'requests']);
        [$a, $b] = $this->server->requests();
        $this->assertSame('{"message":"a","source":"laravel","environment":"production"}', $a['body']);
        $this->assertSame('{"message":"b","source":"cron","environment":"production","channel":"requests"}', $b['body']);
    }

    public function testHelpers(): void
    {
        $honk = $this->client();
        $honk->problem('db/backup', 'Backup failed', 'pg_dump exited with 1', ['idempotencyKey' => 'p-1', 'channel' => 'backups']);
        $honk->recovery('db/backup', 'Backup OK', 'pg_dump finished');
        $honk->warning('Disk 85%', 'app-01 /var', ['severity' => 'info']);
        $honk->critical(null, 'Payments down');
        $honk->problem('q/failed', 'Queue', 'failing', ['severity' => 'critical']);
        $r = $this->server->requests();
        $this->assertSame(['title' => 'Backup failed', 'message' => 'pg_dump exited with 1', 'severity' => 'error', 'channel' => 'backups', 'group_key' => 'db/backup', 'event_type' => 'problem'], json_decode($r[0]['body'], true));
        $this->assertSame('p-1', $r[0]['headers']['idempotency-key']);
        $this->assertSame(['title' => 'Backup OK', 'message' => 'pg_dump finished', 'severity' => 'success', 'group_key' => 'db/backup', 'event_type' => 'recovery'], json_decode($r[1]['body'], true));
        $this->assertSame('warning', json_decode($r[2]['body'], true)['severity']);
        $this->assertSame(['message' => 'Payments down', 'severity' => 'critical'], json_decode($r[3]['body'], true));
        $this->assertSame('critical', json_decode($r[4]['body'], true)['severity']);
    }

    public function testDuplicateAndExplicitKey(): void
    {
        $this->server->script([MockServer::accepted(true)]);
        $res = $this->client()->send(Message::make('x')->idempotencyKey('deploy-4812'));
        $this->assertTrue($res->duplicate);
        $this->assertSame('deploy-4812', $this->server->requests()[0]['headers']['idempotency-key']);
    }

    public function testUrlIsNormalised(): void
    {
        (new Client(url: $this->server->url . '/v1/messages/', key: self::KEY))->send(['message' => 'x']);
        $this->assertSame('/v1/messages', $this->server->requests()[0]['path']);
    }

    public function testRetriesReuseTheSameKeyAndBody(): void
    {
        $this->server->script([MockServer::error(503, 'unavailable', [], ['Retry-After' => '0']), MockServer::error(500, 'internal'), MockServer::accepted()]);
        $res = $this->client()->send(['message' => 'x']);
        $this->assertSame('msg_01k6h3w4z5x6y7z8a9b0c1d2e3', $res->id);
        $r = $this->server->requests();
        $this->assertCount(3, $r);
        $this->assertCount(1, array_unique(array_map(static fn ($x) => $x['headers']['idempotency-key'], $r)));
        $this->assertCount(1, array_unique(array_column($r, 'body')));
    }

    public function testRetriesTimedOutAttemptWithSameKey(): void
    {
        $this->server->script([MockServer::accepted() + ['delayMs' => 600], MockServer::accepted()]);
        $this->client(['timeoutMs' => 150])->send(['message' => 'x'], 'k-1');
        $r = $this->server->requests();
        $this->assertCount(2, $r);
        $this->assertSame(['k-1', 'k-1'], array_map(static fn ($x) => $x['headers']['idempotency-key'], $r));
    }

    public function testHonoursRetryAfter(): void
    {
        $this->server->script([MockServer::error(429, 'rate_limited', [], ['Retry-After' => '1']), MockServer::accepted()]);
        $start = microtime(true);
        $this->client()->send(['message' => 'x']);
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $start);
    }

    public function testRetryAfterBeyondDeadlineFailsFast(): void
    {
        $this->server->script([MockServer::error(429, 'quota_exceeded', ['limit' => 'messages_per_day'], ['Retry-After' => '7200'])]);
        $start = microtime(true);
        try {
            $this->client()->send(['message' => 'x'], 'q-1');
            $this->fail('expected QuotaException');
        } catch (QuotaException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
            $this->assertSame(429, $e->status);
            $this->assertSame(7200, $e->retryAfter);
            $this->assertSame(1, $e->attempts);
            $this->assertSame('q-1', $e->idempotencyKey);
            $this->assertTrue($e->isRetryable());
        }
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertCount(1, $this->server->requests());
    }

    public function testGivesUpAfterRetries(): void
    {
        $this->server->script([MockServer::error(503, 'unavailable', [], ['Retry-After' => '0'])]);
        try {
            $this->client(['retries' => 2])->send(['message' => 'x']);
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(3, $e->attempts);
            $this->assertSame('req_test', $e->requestId);
            $this->assertSame('unavailable', $e->errorCode);
        }
        $this->assertCount(3, $this->server->requests());
    }

    public function testStopsAtDeadline(): void
    {
        $this->server->script([MockServer::error(503, 'unavailable', [], ['Retry-After' => '1'])]);
        $start = microtime(true);
        try {
            $this->client(['retries' => 10, 'deadlineMs' => 1500])->send(['message' => 'x']);
            $this->fail('expected ServerException');
        } catch (ServerException) {
        }
        $this->assertLessThan(1.6, microtime(true) - $start);
        $this->assertCount(2, $this->server->requests());
    }

    public function testTimeoutException(): void
    {
        $this->server->script([MockServer::accepted() + ['delayMs' => 500]]);
        try {
            $this->client(['timeoutMs' => 50, 'retries' => 1])->send(['message' => 'x']);
            $this->fail('expected TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertInstanceOf(NetworkException::class, $e);
            $this->assertSame('timeout', $e->errorCode);
            $this->assertSame(2, $e->attempts);
        }
    }

    public function testConnectionRefused(): void
    {
        $port = MockServer::freePort();
        try {
            (new Client(url: "http://127.0.0.1:{$port}", key: self::KEY, retries: 1, backoffBaseMs: 1))->send(['message' => 'x']);
            $this->fail('expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertNotInstanceOf(TimeoutException::class, $e);
            $this->assertSame('network_error', $e->errorCode);
            $this->assertMatchesRegularExpression(self::UUIDV7, (string) $e->idempotencyKey);
            $this->assertSame(2, $e->attempts);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, class-string, string}> */
    public static function errorCases(): iterable
    {
        yield '422 validation_failed' => [MockServer::error(422, 'validation_failed', ['fields' => [['field' => 'severity', 'code' => 'invalid_enum', 'message' => 'must be one of …']]]), ValidationException::class, 'validation_failed'];
        yield '422 unknown_field' => [MockServer::error(422, 'unknown_field', ['fields' => [['field' => 'foo', 'code' => 'not_allowed']]]), ValidationException::class, 'unknown_field'];
        yield '413 payload_too_large' => [MockServer::error(413, 'payload_too_large'), ValidationException::class, 'payload_too_large'];
        yield '401 invalid_key' => [MockServer::error(401, 'invalid_key'), AuthException::class, 'invalid_key'];
        yield '403 priority_not_allowed' => [MockServer::error(403, 'priority_not_allowed'), AuthException::class, 'priority_not_allowed'];
        yield '403 project_suspended' => [MockServer::error(403, 'project_suspended'), AuthException::class, 'project_suspended'];
        yield '409 idempotency_conflict' => [MockServer::error(409, 'idempotency_conflict'), ConflictException::class, 'idempotency_conflict'];
        yield '404 not_found' => [MockServer::error(404, 'not_found'), HonkException::class, 'not_found'];
    }

    /**
     * @param array<string, mixed> $step
     * @param class-string         $type
     */
    #[DataProvider('errorCases')]
    public function testErrorMappingIsNeverRetried(array $step, string $type, string $code): void
    {
        $this->server->script([$step]);
        try {
            $this->client()->send(['message' => 'x']);
            $this->fail("expected {$type}");
        } catch (HonkException $e) {
            $this->assertSame($type, $e::class);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($step['status'], $e->status);
            $this->assertSame('req_test', $e->requestId);
            $this->assertSame(1, $e->attempts);
            $this->assertFalse($e->isRetryable());
            if ($e instanceof ValidationException) {
                $this->assertFalse($e->local);
            }
        }
        $this->assertCount(1, $this->server->requests());
    }

    public function testValidationExceptionExposesFields(): void
    {
        $this->server->script([MockServer::error(422, 'validation_failed', ['fields' => [['field' => 'image_url', 'code' => 'invalid_format', 'message' => 'must be https']]])]);
        try {
            $this->client()->send(['message' => 'x']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(1, $e->fields);
            $this->assertSame(['image_url', 'invalid_format', 'must be https'], [$e->fields[0]->field, $e->fields[0]->code, $e->fields[0]->message]);
            $this->assertStringContainsString('image_url must be https', $e->getMessage());
        }
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $this->server->script([['status' => 301, 'headers' => ['Location' => 'https://honk.example.com/v1/messages'], 'body' => '']]);
        $this->expectExceptionMessageMatches('~redirect to https://honk\.example\.com~');
        try {
            $this->client()->send(['message' => 'x']);
        } finally {
            $this->assertCount(1, $this->server->requests());
        }
    }

    public function testProxyHtmlErrorIsRetriedThenReported(): void
    {
        $this->server->script([['status' => 502, 'body' => '<html>Bad Gateway</html>']]);
        try {
            $this->client(['retries' => 1])->send(['message' => 'x']);
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(502, $e->status);
            $this->assertNull($e->errorCode);
        }
        $this->assertCount(2, $this->server->requests());
    }

    public function testValidateFalseLeavesChecksToTheServer(): void
    {
        $this->server->script([MockServer::error(422, 'validation_failed', ['fields' => [['field' => 'severity', 'code' => 'invalid_enum']]])]);
        try {
            $this->client(['validate' => false])->send(['message' => 'x', 'severity' => 'fatal']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertFalse($e->local);
        }
        $this->assertSame('fatal', json_decode($this->server->requests()[0]['body'], true)['severity']);
    }

    public function testConstructorRejectsBadConfigClearly(): void
    {
        foreach ([
            [['url' => '', 'key' => self::KEY], 'HONK_URL'],
            [['url' => 'honk.example.com', 'key' => self::KEY], 'https://'],
            [['url' => 'https://h', 'key' => ''], 'HONK_KEY'],
            [['url' => 'https://h', 'key' => 'hka_mobile'], 'honk_'],
            [['url' => 'https://h', 'key' => self::KEY, 'retries' => -1], 'retries'],
        ] as [$args, $needle]) {
            try {
                new Client(...$args);
                $this->fail('expected InvalidArgumentException for ' . json_encode($args));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    public function testFromEnv(): void
    {
        $saved = $_ENV;
        $_ENV = ['HONK_URL' => $this->server->url, 'HONK_KEY' => self::KEY, 'HONK_SOURCE' => 'cron', 'HONK_ENVIRONMENT' => 'staging'] + $_ENV;
        try {
            Client::fromEnv(['retries' => 0])->send(['message' => 'x']);
            $this->assertSame('{"message":"x","source":"cron","environment":"staging"}', $this->server->requests()[0]['body']);
        } finally {
            $_ENV = $saved;
        }
    }

    public function testRetryAfterParsing(): void
    {
        $now = strtotime('2026-10-02T10:00:00Z');
        $this->assertSame(3, Client::retryAfter('3'));
        $this->assertSame(120, Client::retryAfter(' 120 '));
        $this->assertNull(Client::retryAfter(null));
        $this->assertNull(Client::retryAfter('soon'));
        $this->assertSame(30, Client::retryAfter('Fri, 02 Oct 2026 10:00:30 GMT', $now));
        $this->assertSame(0, Client::retryAfter('Fri, 02 Oct 2026 09:00:00 GMT', $now));
    }
}
