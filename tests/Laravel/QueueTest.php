<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Exception\AuthException;
use HonkMe\Exception\ServerException;
use HonkMe\Exception\ValidationException;
use HonkMe\Laravel\Facades\Honk;
use HonkMe\Laravel\HonkManager;
use HonkMe\Laravel\Jobs\SendHonkMessage;
use HonkMe\Laravel\Notifications\HonkMessage;
use HonkMe\Tests\Support\MockServer;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class QueueTest extends TestCase
{
    public function testQueueValidatesNowAndFixesKeyAndDefaults(): void
    {
        Queue::fake();
        Honk::queue(HonkMessage::make('Ana asked for a quote')->title('New request')->groupKey('requests/4812')->occurredAt(new \DateTimeImmutable('2026-10-02T21:10:00Z')));
        Queue::assertPushed(SendHonkMessage::class, function (SendHonkMessage $job): bool {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7/', $job->idempotencyKey);
            $this->assertSame([
                'title' => 'New request',
                'message' => 'Ana asked for a quote',
                'source' => 'laravel',
                'environment' => 'testing',
                'groupKey' => 'requests/4812',
                'occurredAt' => '2026-10-02T21:10:00.000Z',
            ], $job->message);

            return true;
        });
    }

    protected function usesQueueRoute($app): void
    {
        $app['config']->set('honk.queue.connection', 'redis');
        $app['config']->set('honk.queue.queue', 'notifications');
        $app['config']->set('honk.queue.tries', 7);
    }

    #[DefineEnvironment('usesQueueRoute')]
    public function testQueueRouteAndExplicitKey(): void
    {
        Queue::fake();
        $job = Honk::queue(['message' => 'x'], 'request-4812');
        $this->assertSame('request-4812', $job->idempotencyKey);
        Queue::assertPushedOn('notifications', SendHonkMessage::class, function (SendHonkMessage $job): bool {
            return $job->idempotencyKey === 'request-4812' && $job->tries === 7;
        });
        // Registered with Queue::route() at boot; the Bus dispatcher applies it when pushing.
        $this->assertSame('redis', app('queue.routes')->getConnection($job));
        $this->assertSame('notifications', app('queue.routes')->getQueue($job));
    }

    public function testTriesAndBackoffComeFromAttributesUnlessConfigured(): void
    {
        $job = new SendHonkMessage(['message' => 'x'], 'k');
        $this->assertNull($job->tries);
        $this->assertSame(5, app('queue')->connection('sync')->getJobTries($job));
        $this->assertSame('10,60,300,900', app('queue')->connection('sync')->getJobBackoff($job));
        config(['honk.queue.tries' => 2, 'honk.queue.backoff' => [1, 2]]);
        $job = new SendHonkMessage(['message' => 'x'], 'k');
        $this->assertSame(2, app('queue')->connection('sync')->getJobTries($job));
        $this->assertSame('1,2', app('queue')->connection('sync')->getJobBackoff($job));
    }

    public function testDelay(): void
    {
        Queue::fake();
        Honk::queue(['message' => 'later'], 'k-delay', 60);
        Queue::assertPushed(SendHonkMessage::class, fn (SendHonkMessage $job): bool => $job->delay === 60);
    }

    public function testQueuedPayloadIsCanonical(): void
    {
        Queue::fake();
        Honk::queue(HonkMessage::make('x')->severity('LOUD'));
        Queue::assertPushed(SendHonkMessage::class, fn (SendHonkMessage $job): bool => $job->message['severity'] === 'warning');
    }

    public function testActionsTravelWithTheQueuedJob(): void
    {
        Queue::fake();
        Honk::queue(HonkMessage::make('Ana asked for a quote')->action('Reply', 'mailto:ana@acme.example')->action('Call Ana', 'tel:+15550134'), 'request-4812');
        $actions = [['title' => 'Reply', 'url' => 'mailto:ana@acme.example'], ['title' => 'Call Ana', 'url' => 'tel:+15550134']];
        $queued = null;
        Queue::assertPushed(SendHonkMessage::class, function (SendHonkMessage $job) use (&$queued, $actions): bool {
            $this->assertSame($actions, $job->message['actions']);
            $queued = serialize($job);

            return true;
        });
        unserialize($queued)->handle(app(HonkManager::class));
        $this->assertSame($actions, $this->lastBody()['actions']);
    }

    public function testInvalidActionsThrowAtDispatch(): void
    {
        Queue::fake();
        try {
            Honk::queue(['message' => 'x', 'actions' => [['title' => 'Run', 'url' => 'javascript:alert(1)']]]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('actions[0].url', $e->fields[0]->field);
        }
        Queue::assertNothingPushed();
    }

    public function testInvalidMessagesThrowAtDispatch(): void
    {
        Queue::fake();
        try {
            Honk::queue(['message' => 'x', 'severity' => 'fatal']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($e->local);
        }
        Queue::assertNothingPushed();
    }

    public function testJobSendsWithTheFixedKey(): void
    {
        $job = new SendHonkMessage(['message' => 'x', 'source' => 'laravel'], 'request-4812');
        $job->handle(app(HonkManager::class));
        $r = $this->server->requests();
        $this->assertSame('request-4812', $r[0]['headers']['idempotency-key']);
        $this->assertSame('{"message":"x","source":"laravel","environment":"testing"}', $r[0]['body']);
    }

    public function testSyncQueueEndToEnd(): void
    {
        config(['queue.default' => 'sync']);
        Honk::queue(['message' => 'via sync queue'], 'sync-1');
        $this->assertSame('sync-1', $this->server->requests()[0]['headers']['idempotency-key']);
    }

    public function testRetryableErrorsAreRethrownForTheQueueBackoff(): void
    {
        $this->server->script([MockServer::error(503, 'unavailable', [], ['Retry-After' => '60'])]);
        $job = (new SendHonkMessage(['message' => 'x'], 'k-1'))->withFakeQueueInteractions();
        $this->expectException(ServerException::class);
        $job->handle(app(HonkManager::class));
    }

    public function testPermanentErrorsFailTheJobAtOnce(): void
    {
        $this->server->script([MockServer::error(401, 'invalid_key')]);
        $job = (new SendHonkMessage(['message' => 'x'], 'k-1'))->withFakeQueueInteractions();
        $job->handle(app(HonkManager::class));
        $job->assertFailedWith(AuthException::class);
    }

    public function testQuotaReleasesUntilTheReset(): void
    {
        $this->server->script([MockServer::error(429, 'quota_exceeded', [], ['Retry-After' => '3600'])]);
        $job = (new SendHonkMessage(['message' => 'x'], 'k-1'))->withFakeQueueInteractions();
        $job->handle(app(HonkManager::class));
        $job->assertReleased(3600);
    }
}
