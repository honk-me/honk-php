<?php

declare(strict_types=1);

namespace HonkMe\Tests\Integration;

use DateTimeImmutable;
use HonkMe\Client;
use HonkMe\Exception\AuthException;
use HonkMe\Exception\ConflictException;
use HonkMe\Exception\ValidationException;
use HonkMe\Message;
use HonkMe\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real Honk server when HONK_URL and HONK_KEY are set (see ../../../README.md,
 * "Integration tests"); skipped otherwise.
 */
final class IntegrationTest extends TestCase
{
    private static string $run;

    public static function setUpBeforeClass(): void
    {
        self::$run = 'php-' . Uuid::v7();
    }

    private function client(array $args = []): Client
    {
        $url = getenv('HONK_URL') ?: '';
        $key = getenv('HONK_KEY') ?: '';
        if ($url === '' || $key === '') {
            $this->markTestSkipped('set HONK_URL and HONK_KEY to run against a real server');
        }

        return new Client(...$args + ['url' => $url, 'key' => $key, 'defaults' => ['source' => 'sdk-php-it', 'environment' => 'test']]);
    }

    public function testMinimalMessageIsAccepted(): void
    {
        $res = $this->client()->send(['message' => 'minimal ' . self::$run]);
        $this->assertStringStartsWith('msg_', $res->id);
        $this->assertFalse($res->duplicate);
    }

    public function testEveryFieldIsAcceptedAndAReplayIsADuplicate(): void
    {
        $honk = $this->client();
        $message = Message::make("Ana (Acme) asked for a quote:\nonline shop, 40 products")
            ->title('Customer request ' . self::$run)
            ->info()
            ->priority('high')
            ->category('customers')
            ->source('sdk-php-it')
            ->environment('test')
            ->channel('requests')
            ->groupKey('requests/' . self::$run)
            ->eventType('event')
            ->occurredAt(new DateTimeImmutable())
            ->url('https://example.com/admin/requests/4812')
            ->imageUrl('https://example.com/images/quote.png')
            ->metadata(['request_id' => '4812', 'amount' => 1250.5, 'vip' => true])
            ->ttlSeconds(600)
            ->sourceSequence(1);
        $key = 'it-' . Uuid::v7();
        $first = $honk->send($message, $key);
        $again = $honk->send($message, $key);
        $this->assertFalse($first->duplicate);
        $this->assertTrue($again->duplicate);
        $this->assertSame($first->id, $again->id);
        $this->assertEquals($first->receivedAt, $again->receivedAt);
    }

    public function testSameKeyDifferentPayloadIsAConflict(): void
    {
        $honk = $this->client();
        $key = 'it-' . Uuid::v7();
        $honk->info('first', 'payload A ' . self::$run, ['idempotencyKey' => $key]);
        try {
            $honk->info('first', 'payload B ' . self::$run, ['idempotencyKey' => $key]);
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('idempotency_conflict', $e->errorCode);
            $this->assertSame($key, $e->idempotencyKey);
        }
    }

    public function testProblemThenRecovery(): void
    {
        $honk = $this->client();
        $group = 'it/php/' . self::$run;
        $p = $honk->problem($group, 'Backup failed', 'pg_dump exited with 1', ['sourceSequence' => 1]);
        $r = $honk->recovery($group, 'Backup OK', 'pg_dump finished', ['sourceSequence' => 2]);
        $this->assertNotSame($p->id, $r->id);
    }

    public function testHornAliasAndCanonicalSeverityAreTheSameEvent(): void
    {
        $honk = $this->client();
        $key = 'it-' . Uuid::v7();
        $first = $honk->loud('Disk 91%', '/var on app-01 ' . self::$run, ['idempotencyKey' => $key]);
        $again = $honk->send(Message::make('/var on app-01 ' . self::$run)->title('Disk 91%')->severity('WARNING'), $key);
        $this->assertTrue($again->duplicate);
        $this->assertSame($first->id, $again->id);
    }

    public function testWrongKeyIsAnAuthException(): void
    {
        try {
            $this->client(['key' => 'honk_000000000000_00000000000000000000000000000000'])->send(['message' => 'x']);
            $this->fail('expected AuthException');
        } catch (AuthException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('invalid_key', $e->errorCode);
        }
    }

    public function testUrgentWithoutAllowUrgent(): void
    {
        try {
            $res = $this->client()->send(['message' => 'urgent ' . self::$run, 'priority' => 'urgent']);
            $this->assertStringStartsWith('msg_', $res->id); // the key allows urgent
        } catch (AuthException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('priority_not_allowed', $e->errorCode);
        }
    }

    public function testServerSideValidationMapsFields(): void
    {
        try {
            $this->client(['validate' => false])->send(['message' => 'x', 'severity' => 'fatal', 'ttlSeconds' => 5]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertFalse($e->local);
            $this->assertSame(422, $e->status);
            $fields = array_map(static fn ($f) => "{$f->field}:{$f->code}", $e->fields);
            sort($fields);
            $this->assertSame(['severity:invalid_enum', 'ttl_seconds:out_of_range'], $fields);
        }
    }
}
