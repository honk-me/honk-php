<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Laravel\Notifications\HonkChannel;
use HonkMe\Laravel\Notifications\HonkMessage;
use HonkMe\Laravel\Notifications\ToHonk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class NotificationChannelTest extends TestCase
{
    public function testToHonkMessageIsSentWithNotificationIdAsKey(): void
    {
        (new Owner())->notify(new CustomerRequested(4812, 'Ana Pop', 'Acme', 'Quote for an online shop'));
        [$r] = $this->server->requests();
        $this->assertMatchesRegularExpression('/^notification-[0-9a-f-]{36}$/', $r['headers']['idempotency-key']);
        $this->assertSame([
            'title' => 'New request: Quote for an online shop',
            'message' => "Ana Pop (Acme)\nQuote for an online shop",
            'priority' => 'high',
            'category' => 'customers',
            'source' => 'laravel',
            'environment' => 'testing',
            'channel' => 'requests',
            'group_key' => 'requests/4812',
            'url' => 'https://shop.example.com/admin/requests/4812',
            'metadata' => ['request_id' => '4812'],
        ], json_decode($r['body'], true));
    }

    public function testQueuedNotificationKeepsItsKeyAcrossRetries(): void
    {
        Queue::fake();
        (new Owner())->notify(new QueuedCustomerRequested(7, 'Ion', 'Beta', 'Website redesign'));
        $queued = null;
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use (&$queued): bool {
            $queued = serialize($job);

            return true;
        });
        // Two worker attempts of the same queued job (each one unserialises the payload).
        unserialize($queued)->handle(app(ChannelManager::class));
        unserialize($queued)->handle(app(ChannelManager::class));
        [$first, $retry] = $this->server->requests();
        $this->assertMatchesRegularExpression('/^notification-[0-9a-f-]{36}$/', $first['headers']['idempotency-key']);
        $this->assertSame($first['headers']['idempotency-key'], $retry['headers']['idempotency-key']);
        $this->assertSame($first['body'], $retry['body']);
    }

    public function testAnonymousNotifiableWithNullRouteUsesTheConfiguredKey(): void
    {
        NotificationFacade::route('honk', null)->notify(new CustomerRequested(9, 'A', 'B', 'C'));
        $this->assertSame('Bearer ' . \HonkMe\Tests\Unit\ClientTest::KEY, $this->server->requests()[0]['headers']['authorization']);
    }

    public function testRouteCanSelectAnotherKeyOrSkip(): void
    {
        $other = 'honk_zz12cd34ef56_0123456789abcdefghijABCDEFGHIJ0123';
        NotificationFacade::route('honk', $other)->notify(new CustomerRequested(1, 'A', 'B', 'C'));
        $this->assertSame("Bearer {$other}", $this->server->requests()[0]['headers']['authorization']);

        (new Owner(['key' => $other, 'defaults' => ['source' => 'shop']]))->notify(new CustomerRequested(2, 'A', 'B', 'C'));
        $this->assertSame('shop', $this->lastBody()['source']);

        (new Owner(false))->notify(new CustomerRequested(3, 'A', 'B', 'C'));
        $this->assertCount(2, $this->server->requests());
    }

    public function testToHonkMayReturnAnArrayOrAString(): void
    {
        (new Owner())->notify(new InlineNotification(['message' => 'from array', 'groupKey' => 'g', 'idempotencyKey' => 'fixed-1']));
        (new Owner())->notify(new InlineNotification('from string'));
        $r = $this->server->requests();
        $this->assertSame('fixed-1', $r[0]['headers']['idempotency-key']);
        $this->assertSame('from array', json_decode($r[0]['body'], true)['message']);
        $this->assertSame('from string', json_decode($r[1]['body'], true)['message']);
    }

    public function testMissingToHonkIsALogicError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement HonkMe\\Laravel\\Notifications\\ToHonk');
        (new Owner())->notify(new WithoutToHonk());
    }
}

class Owner
{
    use Notifiable;

    public function __construct(private readonly mixed $honkRoute = null)
    {
    }

    public function routeNotificationForHonk(): mixed
    {
        return $this->honkRoute;
    }
}

class CustomerRequested extends Notification implements ToHonk
{
    public function __construct(public int $requestId, public string $name, public string $company, public string $summary)
    {
    }

    public function via(object $notifiable): array
    {
        return ['honk'];
    }

    public function toHonk(object $notifiable): HonkMessage
    {
        return HonkMessage::make()
            ->title("New request: {$this->summary}")
            ->line("{$this->name} ({$this->company})")
            ->line($this->summary)
            ->priority('high')
            ->category('customers')
            ->channel('requests')
            ->groupKey("requests/{$this->requestId}")
            ->url("https://shop.example.com/admin/requests/{$this->requestId}")
            ->meta('request_id', (string) $this->requestId);
    }
}

class QueuedCustomerRequested extends CustomerRequested implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return [HonkChannel::class];
    }
}

class InlineNotification extends Notification
{
    public function __construct(private readonly array|string $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return ['honk'];
    }

    public function toHonk(object $notifiable): array|string
    {
        return $this->payload;
    }
}

class WithoutToHonk extends Notification
{
    public function via(object $notifiable): array
    {
        return ['honk'];
    }
}
