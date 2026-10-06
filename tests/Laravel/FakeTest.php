<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Exception\ValidationException;
use HonkMe\Laravel\Facades\Honk;
use HonkMe\Laravel\HonkManager;
use HonkMe\Laravel\Notifications\HonkMessage;
use HonkMe\Laravel\Testing\HonkFake;
use HonkMe\Message;
use HonkMe\Severity;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    public function testFakeRecordsEveryKindOfSendWithoutTouchingTheNetwork(): void
    {
        $fake = Honk::fake();
        $this->assertInstanceOf(HonkFake::class, $fake);
        $this->assertSame($fake, app(HonkManager::class));

        $accepted = Honk::loud('Disk 91%', '/var on app-01', ['groupKey' => 'disk/var']);
        $this->assertStringStartsWith('msg_fake', $accepted->id);
        Honk::send(HonkMessage::create('Ana asked for a quote')->title('New request')->groupKey('requests/1'), 'request-1');
        Honk::defer()->blast('Payments down', 'Stripe 500s');
        Honk::problem('db/backup', 'Backup failed', 'exit 1');
        Honk::queue(['message' => 'queued one', 'severity' => 'BEEP'], 'queued-1');

        Honk::assertSentTimes(4);
        Honk::assertSent(fn (Message $m) => $m->severity === Severity::Loud && $m->groupKey === 'disk/var');
        Honk::assertSent(fn (Message $m, string $key) => $m->groupKey === 'requests/1' && $key === 'request-1');
        Honk::assertSent(fn (Message $m) => $m->severity === Severity::Blast);
        Honk::assertSent(fn (Message $m) => $m->eventType === 'problem' && $m->severity === Severity::Long);
        Honk::assertNotSent(fn (Message $m) => $m->message === 'queued one');
        Honk::assertQueued(1);
        Honk::assertQueued(fn (Message $m, string $key) => $m->severity === Severity::Beep && $key === 'queued-1');
        $this->assertCount(4, Honk::sent());
        $this->assertSame([], $this->server->requests());
    }

    public function testRecordedMessagesHaveDefaultsApplied(): void
    {
        Honk::fake();
        Honk::light(null, 'x');
        Honk::assertSent(fn (Message $m) => $m->source === 'laravel' && $m->environment === 'testing' && $m->title === null);
    }

    public function testRecordedMessagesKeepTheirActions(): void
    {
        Honk::fake();
        Honk::send(['message' => 'Ana asked for a quote', 'actions' => [['title' => 'Call Ana', 'url' => 'tel:+15550134']]]);
        Honk::defer()->light('New request', 'Ana asked for a quote', ['actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example']]]);
        Honk::assertSent(fn (Message $m) => $m->actions === [['title' => 'Call Ana', 'url' => 'tel:+15550134']]);
        Honk::assertSent(fn (Message $m) => ($m->actions[0]['url'] ?? null) === 'mailto:ana@acme.example');
    }

    public function testInvalidMessagesStillThrow(): void
    {
        Honk::fake();
        $this->expectException(ValidationException::class);
        Honk::send(['message' => 'x', 'url' => 'http://insecure.example.com']);
    }

    public function testNothingAssertionsAndFailures(): void
    {
        Honk::fake();
        Honk::assertNothingSent();
        Honk::assertNothingQueued();
        Honk::assertNothingOutgoing();

        Honk::beep('a', 'b');
        $this->assertFails(fn () => Honk::assertNothingSent());
        $this->assertFails(fn () => Honk::assertSentTimes(2));
        $this->assertFails(fn () => Honk::assertSent(fn (Message $m) => $m->title === 'nope'));
        $this->assertFails(fn () => Honk::assertQueued());
        $this->assertFails(fn () => Honk::assertNotSent(fn (Message $m) => $m->title === 'a'));
    }

    private function assertFails(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the assertion to fail.');
    }
}
