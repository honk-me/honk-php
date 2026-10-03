<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Exception\ValidationException;
use HonkMe\Laravel\Facades\Honk;
use Illuminate\Support\Facades\Route;

final class DeferTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/ok', function () {
            Honk::defer()->loud('Disk 91%', '/var on app-01');

            return response('done');
        });
        Route::get('/fails', function () {
            Honk::defer()->long('Skipped', 'request failed');
            Honk::defer(always: true)->blast('Always', 'sent even though the request failed');
            abort(500);
        });
    }

    public function testDeferredHonkIsSentAfterTheResponse(): void
    {
        $this->get('/ok')->assertOk()->assertSee('done');
        $requests = $this->server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('warning', json_decode($requests[0]['body'], true)['severity']);
    }

    public function testFailedRequestsSkipDeferredHonksUnlessAlways(): void
    {
        $this->get('/fails')->assertStatus(500);
        $sent = array_map(static fn ($r) => json_decode($r['body'], true)['title'], $this->server->requests());
        $this->assertSame(['Always'], $sent);
    }

    public function testDeferredMessagesAreValidatedImmediately(): void
    {
        $this->expectException(ValidationException::class);
        Honk::defer()->loud('Bad', 'link', ['url' => 'ftp://example.com']);
    }

    public function testWithoutDeferSendsAtOnce(): void
    {
        $this->withoutDefer();
        Honk::defer()->beep('Now', 'immediately');
        $this->assertCount(1, $this->server->requests());
    }
}
