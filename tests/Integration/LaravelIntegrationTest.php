<?php

declare(strict_types=1);

namespace HonkMe\Tests\Integration;

use HonkMe\Laravel\Facades\Honk;
use HonkMe\Laravel\HonkServiceProvider;
use HonkMe\Laravel\Notifications\HonkMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Orchestra\Testbench\TestCase;

/** The Laravel recipe end to end against a real server (HONK_URL / HONK_KEY); skipped otherwise. */
final class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HonkServiceProvider::class];
    }

    protected function setUp(): void
    {
        if (!getenv('HONK_URL') || !getenv('HONK_KEY')) {
            $this->markTestSkipped('set HONK_URL and HONK_KEY to run against a real server');
        }
        parent::setUp();
        config(['honk.url' => getenv('HONK_URL'), 'honk.key' => getenv('HONK_KEY'), 'honk.defaults.source' => 'sdk-laravel-it', 'queue.default' => 'sync']);
    }

    public function testCustomerRequestNotificationAndQueuedSend(): void
    {
        $owner = new class () {
            use Notifiable;
        };
        $notification = new class () extends Notification {
            public function via(object $notifiable): array
            {
                return ['honk'];
            }

            public function toHonk(object $notifiable): HonkMessage
            {
                return HonkMessage::make('Ana Pop (Acme) asked for a quote: online shop, 40 products')
                    ->title('New request: online shop quote')
                    ->priority('high')
                    ->category('customers')
                    ->channel('requests')
                    ->groupKey('requests/it-' . uniqid())
                    ->url('https://shop.example.com/admin/requests/4812')
                    ->meta('request_id', '4812');
            }
        };
        $owner->notify($notification);

        Honk::queue(['message' => 'queued from Laravel', 'groupKey' => 'it/laravel/queue']);
        $this->addToAssertionCount(1); // the calls above throw on any failure
    }

    public function testDeferExceptionReportingAndTheTestCommand(): void
    {
        $sent = [];
        \Illuminate\Support\Facades\Event::listen(\HonkMe\Laravel\Events\HonkSent::class, function ($e) use (&$sent): void {
            $sent[] = $e->via . ':' . $e->accepted->id;
        });

        $this->withoutDefer();
        Honk::defer()->loud('Deferred from Laravel', 'sent after the response');

        config(['honk.exceptions.mode' => 'sync', 'cache.default' => 'array']);
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(Honk::reportable());
        report(new \RuntimeException('Integration test exception'));

        $this->artisan('honk:test')->assertSuccessful()->expectsOutputToContain('Accepted msg_');

        $this->assertCount(3, $sent, implode(', ', $sent));
        $this->assertStringStartsWith('defer:msg_', $sent[0]);
        $this->assertStringStartsWith('send:msg_', $sent[1]);
    }
}
