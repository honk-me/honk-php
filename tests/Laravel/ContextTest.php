<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Laravel\Facades\Honk;
use HonkMe\Message;
use Illuminate\Support\Facades\Context;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class ContextTest extends TestCase
{
    protected function enableContext($app): void
    {
        $app['config']->set('honk.context.enabled', true);
        $app['config']->set('honk.context.keys', ['request_id', 'route', 'user_id', 'missing']);
    }

    #[DefineEnvironment('enableContext')]
    public function testAllowListedContextGoesIntoMetadata(): void
    {
        Context::add(['request_id' => 'req-123', 'route' => 'quotes.store', 'user_id' => 42, 'email' => 'ana@example.com']);
        Context::addHidden('user_id_hidden', 7);
        Honk::light('New request', 'x', ['metadata' => ['route' => 'explicit']]);
        $this->assertSame(
            ['route' => 'explicit', 'request_id' => 'req-123', 'user_id' => 42],
            json_decode($this->server->requests()[0]['body'], true)['metadata'],
        );
    }

    #[DefineEnvironment('enableContext')]
    public function testTheSixteenKeyLimitIsRespected(): void
    {
        Context::add('request_id', 'req-1');
        $metadata = [];
        for ($i = 0; $i < 16; $i++) {
            $metadata["k{$i}"] = $i;
        }
        Honk::fake();
        Honk::light('t', 'm', ['metadata' => $metadata]);
        Honk::assertSent(fn (Message $m) => count($m->metadata ?? []) === 16 && !isset($m->metadata['request_id']));
    }

    public function testContextIsOffByDefault(): void
    {
        Context::add('request_id', 'req-123');
        Honk::light('t', 'm');
        $this->assertArrayNotHasKey('metadata', json_decode($this->server->requests()[0]['body'], true));
    }

    #[DefineEnvironment('enableContext')]
    public function testQueuedMessagesCaptureContextAtDispatch(): void
    {
        Context::add('request_id', 'req-queued');
        Honk::fake();
        Honk::queue(['message' => 'x']);
        Honk::assertQueued(fn (Message $m) => ($m->metadata['request_id'] ?? null) === 'req-queued');
    }
}
