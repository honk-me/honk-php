<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Exception\NetworkException;
use HonkMe\Laravel\Exceptions\ExceptionHonker;
use HonkMe\Laravel\Facades\Honk;
use HonkMe\Message;
use HonkMe\Severity;
use Illuminate\Contracts\Debug\ExceptionHandler;
use LogicException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

final class ExceptionReportingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    private function registerReporter(): void
    {
        $this->app->make(ExceptionHandler::class)->reportable(Honk::reportable());
    }

    public function testReportedExceptionsBecomeGroupedThrottledProblems(): void
    {
        Honk::fake();
        $this->registerReporter();
        $line = __LINE__ + 2;
        for ($i = 0; $i < 3; $i++) {
            report(new RuntimeException("Payment provider timeout #{$i}"));
        }
        report(new LogicException('another place'));

        Honk::assertSentTimes(2);
        // "exceptions/<class>@<file relative to base_path()>:<line>" (absolute under Testbench).
        Honk::assertSent(function (Message $m) use ($line) {
            return str_starts_with((string) $m->groupKey, 'exceptions/RuntimeException@')
                && str_ends_with((string) $m->groupKey, "ExceptionReportingTest.php:{$line}")
                && $m->eventType === 'problem'
                && $m->severity === Severity::Long
                && $m->channel === 'exceptions'
                && $m->title === 'RuntimeException: Payment provider timeout #0'
                && str_contains((string) $m->message, "ExceptionReportingTest.php:{$line}");
        });
    }

    public function testHonkFailuresAreNeverHonked(): void
    {
        Honk::fake();
        $this->registerReporter();
        report(new NetworkException('Could not reach Honk'));
        Honk::assertNothingOutgoing();
    }

    public function testSeverityAndThrottleCanBeChosenPerCall(): void
    {
        Honk::fake();
        $this->app->make(ExceptionHandler::class)->reportable(Honk::reportable(severity: 'blast', throttleSeconds: 0));
        report(new RuntimeException('a'));
        report(new RuntimeException('a'));
        Honk::assertSentTimes(2, fn (Message $m) => $m->severity === Severity::Blast);
    }

    protected function syncMode($app): void
    {
        $app['config']->set('honk.exceptions.mode', 'sync');
        $app['config']->set('cache.default', 'array');
    }

    #[DefineEnvironment('syncMode')]
    public function testControlCharactersInExceptionMessagesAreCleanedForTheWire(): void
    {
        $this->registerReporter();
        report(new RuntimeException("bad\x07bytes\x00here"));
        $body = json_decode($this->server->requests()[0]['body'], true);
        $this->assertSame('RuntimeException: bad bytes here', $body['title']);
        $this->assertSame('error', $body['severity']);
        $this->assertSame(['exception' => 'RuntimeException', 'line' => $body['metadata']['line']], $body['metadata']);
    }

    public function testGroupKeysAreRelativeAndShortenedWithAHash(): void
    {
        $e = new RuntimeException('x');
        $this->assertSame('exceptions/RuntimeException@ExceptionReportingTest.php:' . (__LINE__ - 1), ExceptionHonker::groupKey($e, __DIR__));
        $this->assertLessThanOrEqual(128, strlen(ExceptionHonker::groupKey($e, '/nowhere')));
        $anonymous = new class ('x') extends RuntimeException {
        };
        $key = ExceptionHonker::groupKey($anonymous, __DIR__);
        $this->assertStringStartsWith('exceptions/RuntimeException@anonymous@ExceptionReportingTest.php:', $key);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $key);
    }

    public function testFailuresWhileReportingAreSwallowed(): void
    {
        config(['honk.url' => 'http://127.0.0.1:1', 'honk.exceptions.mode' => 'sync', 'honk.deadline' => 1, 'honk.retries' => 0]);
        app()->forgetInstance(\HonkMe\Laravel\HonkManager::class);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance(\HonkMe\Laravel\HonkManager::class);
        app()->forgetInstance(ExceptionHonker::class);
        $this->registerReporter();
        report(new RuntimeException('still fine'));
        $this->addToAssertionCount(1); // no exception escaped
    }

    private function unconfigure(): void
    {
        config(['honk.url' => null, 'honk.key' => null]);
        $this->app->forgetInstance(\HonkMe\Laravel\HonkManager::class);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance(\HonkMe\Laravel\HonkManager::class);
    }

    public function testUnconfiguredHonkIsANoOpForExceptions(): void
    {
        $this->unconfigure();
        $this->withoutDefer();
        $this->registerReporter();
        report(new RuntimeException('local development without HONK_URL'));
        $this->assertSame([], $this->server->requests());
    }

    public function testFakesRecordExceptionsEvenWithoutConfiguration(): void
    {
        $this->unconfigure();
        Honk::fake();
        $this->registerReporter();
        report(new RuntimeException('recorded'));
        Honk::assertSentTimes(1);
    }

    public function testDeferredSendsNeverThrowFromTheCallback(): void
    {
        $this->unconfigure();
        \Illuminate\Support\Facades\Route::get('/deferred', function () {
            Honk::defer()->loud('Configured?', 'no');

            return 'ok';
        });
        $this->get('/deferred')->assertOk()->assertSee('ok');
    }
}
