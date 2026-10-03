<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Laravel\Facades\Honk;
use HonkMe\Message;
use HonkMe\Severity;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

final class ScheduleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    private function runEvent(Event $event): void
    {
        $event->run($this->app);
    }

    public function testHonkOnFailureSendsAProblemThenARecoveryOnlyAfterAFailure(): void
    {
        Honk::fake();
        $schedule = $this->app->make(Schedule::class);
        $ok = $schedule->exec('true')->description('backup:run')->honkOnFailure();
        $failing = $schedule->exec('sh -c "echo disk full; exit 3"')->description('backup:run')->honkOnFailure();

        $this->runEvent($ok);            // healthy: nothing
        Honk::assertNothingSent();

        $this->runEvent($failing);       // fails: a long honk problem with the output tail
        Honk::assertSentTimes(1);
        Honk::assertSent(fn (Message $m) => $m->eventType === 'problem'
            && $m->groupKey === 'schedule/backup:run'
            && $m->severity === Severity::Long
            && str_contains((string) $m->message, 'Exit code: 3')
            && str_contains((string) $m->message, 'disk full'));

        $this->runEvent($ok);            // recovers once
        $this->runEvent($ok);            // and stays quiet afterwards
        Honk::assertSentTimes(2);
        Honk::assertSent(fn (Message $m) => $m->eventType === 'recovery' && $m->groupKey === 'schedule/backup:run' && $m->severity === Severity::Beep);
    }

    public function testHonkOnSuccess(): void
    {
        Honk::fake();
        $schedule = $this->app->make(Schedule::class);
        $this->runEvent($schedule->exec('true')->description('reports:send')->honkOnSuccess());
        $this->runEvent($schedule->exec('false')->description('reports:send')->honkOnSuccess());
        Honk::assertSentTimes(1, fn (Message $m) => $m->severity === Severity::Beep && $m->title === 'Done: reports:send');
    }

    public function testGroupKeyFromAnArtisanCommand(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $event = $schedule->command('inspire --quiet');
        $this->assertSame('schedule/inspire --quiet', \HonkMe\Laravel\Scheduling\ScheduleHonk::groupKeyFor($event));
    }
}
