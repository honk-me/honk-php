<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Exception\AuthException;
use HonkMe\Laravel\Events\HonkFailed;
use HonkMe\Laravel\Events\HonkSent;
use HonkMe\Laravel\Facades\Honk;
use HonkMe\Tests\Support\MockServer;
use Illuminate\Support\Facades\Event;

final class EventsTest extends TestCase
{
    public function testHonkSentAndHonkFailed(): void
    {
        Event::fake([HonkSent::class, HonkFailed::class]);
        Honk::beep('Deployed', 'v4.2', ['idempotencyKey' => 'deploy-42']);
        Event::assertDispatched(HonkSent::class, fn (HonkSent $e) => $e->idempotencyKey === 'deploy-42'
            && $e->via === 'send'
            && $e->accepted->id === 'msg_01k6h3w4z5x6y7z8a9b0c1d2e3');

        $this->server->script([MockServer::error(401, 'invalid_key')]);
        try {
            Honk::long('Broken', 'key');
            $this->fail('expected AuthException');
        } catch (AuthException) {
        }
        Event::assertDispatched(HonkFailed::class, fn (HonkFailed $e) => $e->exception instanceof AuthException && $e->message->title === 'Broken');
    }
}
