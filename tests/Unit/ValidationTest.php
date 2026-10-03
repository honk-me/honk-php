<?php

declare(strict_types=1);

namespace HonkMe\Tests\Unit;

use DateTimeImmutable;
use HonkMe\Client;
use HonkMe\Exception\ValidationException;
use HonkMe\Message;
use HonkMe\Uuid;
use HonkMe\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalid(): iterable
    {
        $many = [];
        for ($i = 0; $i < 17; $i++) {
            $many["k{$i}"] = $i;
        }
        $big = [];
        for ($i = 0; $i < 16; $i++) {
            $big["k{$i}"] = str_repeat('"', 500);
        }
        yield 'message required' => [[], 'message', 'required'];
        yield 'message blank' => [['message' => '   '], 'message', 'too_short'];
        yield 'message bytes' => [['message' => str_repeat('x', 8193)], 'message', 'too_long'];
        yield 'message multibyte bytes' => [['message' => str_repeat('é', 4097)], 'message', 'too_long'];
        yield 'message control' => [['message' => "bell\x07"], 'message', 'invalid_format'];
        yield 'message utf8' => [['message' => "bad \xFF utf8"], 'message', 'invalid_utf8'];
        yield 'title long' => [['message' => 'x', 'title' => str_repeat('t', 161)], 'title', 'too_long'];
        yield 'title line break' => [['message' => 'x', 'title' => "two\nlines"], 'title', 'invalid_format'];
        yield 'environment long' => [['message' => 'x', 'environment' => str_repeat('e', 33)], 'environment', 'too_long'];
        yield 'group key long' => [['message' => 'x', 'groupKey' => str_repeat('g', 129)], 'group_key', 'too_long'];
        yield 'severity' => [['message' => 'x', 'severity' => 'fatal'], 'severity', 'invalid_enum'];
        yield 'priority' => [['message' => 'x', 'priority' => 'asap'], 'priority', 'invalid_enum'];
        yield 'category' => [['message' => 'x', 'category' => 'crm'], 'category', 'invalid_enum'];
        yield 'recovery without group' => [['message' => 'x', 'eventType' => 'recovery'], 'group_key', 'requires_group_key'];
        yield 'sequence without group' => [['message' => 'x', 'sourceSequence' => 3], 'source_sequence', 'requires_group_key'];
        yield 'sequence negative' => [['message' => 'x', 'groupKey' => 'g', 'sourceSequence' => -1], 'source_sequence', 'out_of_range'];
        yield 'sequence too big' => [['message' => 'x', 'groupKey' => 'g', 'sourceSequence' => 9007199254740992], 'source_sequence', 'out_of_range'];
        yield 'http url' => [['message' => 'x', 'url' => 'http://example.com'], 'url', 'invalid_format'];
        yield 'url credentials' => [['message' => 'x', 'url' => 'https://user:pw@example.com'], 'url', 'invalid_format'];
        yield 'url long' => [['message' => 'x', 'url' => 'https://example.com/' . str_repeat('a', 2048)], 'url', 'invalid_format'];
        yield 'image fragment' => [['message' => 'x', 'imageUrl' => 'https://cdn.example.com/a.jpg#x'], 'image_url', 'invalid_format'];
        yield 'image port' => [['message' => 'x', 'imageUrl' => 'https://cdn.example.com:99999/a.jpg'], 'image_url', 'invalid_format'];
        yield 'occurred at format' => [['message' => 'x', 'occurredAt' => '2026-10-02 10:00'], 'occurred_at', 'invalid_format'];
        yield 'metadata nested' => [['message' => 'x', 'metadata' => ['nested' => ['a' => 1]]], 'metadata.nested', 'invalid_format'];
        yield 'metadata key' => [['message' => 'x', 'metadata' => ['bad key' => 1]], 'metadata.bad key', 'invalid_format'];
        yield 'metadata string' => [['message' => 'x', 'metadata' => ['v' => str_repeat('v', 513)]], 'metadata.v', 'invalid_format'];
        yield 'metadata nan' => [['message' => 'x', 'metadata' => ['n' => NAN]], 'metadata.n', 'invalid_format'];
        yield 'metadata null' => [['message' => 'x', 'metadata' => ['n' => null]], 'metadata.n', 'invalid_format'];
        yield 'metadata keys' => [['message' => 'x', 'metadata' => $many], 'metadata', 'too_long'];
        yield 'ttl low' => [['message' => 'x', 'ttlSeconds' => 59], 'ttl_seconds', 'out_of_range'];
        yield 'ttl high' => [['message' => 'x', 'ttlSeconds' => 86401], 'ttl_seconds', 'out_of_range'];
        yield 'snake case' => [['message' => 'x', 'group_key' => 'g'], 'group_key', 'not_allowed'];
        yield 'wrong type' => [['message' => 'x', 'ttlSeconds' => 'soon'], 'ttl_seconds', 'invalid_format'];
        yield 'body size' => [['message' => str_repeat('x', 8000), 'metadata' => $big], 'body', 'too_long'];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalid')]
    public function testRejectsLocally(array $fields, string $field, string $code): void
    {
        $client = new Client(url: 'https://honk.example.com', key: ClientTest::KEY);
        try {
            $client->send($fields);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($e->local);
            $this->assertSame(0, $e->attempts);
            $found = array_filter($e->fields, static fn ($f) => $f->field === $field && $f->code === $code);
            $this->assertNotEmpty($found, "want {$field}/{$code}, got " . $e->getMessage());
        }
    }

    public function testSnakeCaseGetsAHint(): void
    {
        $this->expectExceptionMessage('group_key unknown field (use groupKey)');
        Message::fromArray(['message' => 'x', 'group_key' => 'g']);
    }

    public function testAllErrorsReportedAtOnce(): void
    {
        try {
            Wire::build(Message::fromArray(['message' => '', 'severity' => 'x', 'url' => 'ftp://a']));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['message', 'severity', 'url'], array_map(static fn ($f) => $f->field, $e->fields));
        }
    }

    public function testValidEdgeCases(): void
    {
        $body = Wire::build(Message::make("line1\nline2\ttab\r\n")
            ->title(str_repeat('t', 160))
            ->occurredAt('2026-10-02T00:10:00.123456+03:00')
            ->url('https://[::1]:8443/path?q=1#frag')
            ->imageUrl('HTTPS://cdn.example.com/a.jpg?size=2')
            ->metadata(['a.b-c_d' => 'v', 'n' => 1.5, 'b' => false, '0' => 'zero'])
            ->groupKey('g')
            ->sourceSequence(9007199254740991)
            ->ttlSeconds(60));
        $this->assertSame(9007199254740991, $body['source_sequence']);
        $this->assertStringContainsString('"metadata":{"a.b-c_d":"v","n":1.5,"b":false,"0":"zero"}', Wire::encode($body));
    }

    public function testCarbonLikeDatesAreUtcWithMilliseconds(): void
    {
        $body = Wire::build(Message::make('x')->occurredAt(new DateTimeImmutable('2026-10-02T00:10:00.5+03:00')));
        $this->assertSame('2026-10-01T21:10:00.500Z', $body['occurred_at']);
    }

    public function testInvalidIdempotencyKeys(): void
    {
        $client = new Client(url: 'https://honk.example.com', key: ClientTest::KEY);
        foreach (['', 'has space', str_repeat('x', 129), 'ünicode'] as $key) {
            try {
                $client->send(['message' => 'x'], $key);
                $this->fail("expected ValidationException for '{$key}'");
            } catch (ValidationException $e) {
                $this->assertSame('Idempotency-Key', $e->fields[0]->field);
            }
        }
    }

    public function testMessageBuilder(): void
    {
        $m = Message::make()->line('Customer: Ana')->line('Company: Acme')->title('New request');
        $this->assertSame(['title' => 'New request', 'message' => "Customer: Ana\nCompany: Acme"], $m->toArray());
        $this->assertSame(['message' => 'x', 'metadata' => ['a' => 1, 'b' => 2]], Message::make('x')->metadata(['a' => 1])->meta('b', 2)->toArray());
    }

    public function testUuidV7(): void
    {
        $a = Uuid::v7(1_700_000_000_000);
        $b = Uuid::v7(1_700_000_000_001);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertLessThan($b, $a);
        $this->assertSame('018bcfe56800', str_replace('-', '', substr($a, 0, 13)));
    }
}
