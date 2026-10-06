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

        $call = ['title' => 'Call', 'url' => 'tel:+15550134'];
        yield 'actions count' => [['message' => 'x', 'actions' => [$call, $call, $call, $call]], 'actions', 'too_long'];
        yield 'actions string' => [['message' => 'x', 'actions' => 'tel:+15550134'], 'actions', 'invalid_format'];
        yield 'actions not a list' => [['message' => 'x', 'actions' => $call], 'actions', 'invalid_format'];
        yield 'action not an array' => [['message' => 'x', 'actions' => ['tel:+15550134']], 'actions', 'invalid_format'];
        yield 'action positional' => [['message' => 'x', 'actions' => [['Call', 'tel:+15550134']]], 'actions', 'invalid_format'];
        yield 'action title missing' => [['message' => 'x', 'actions' => [['url' => 'tel:+15550134']]], 'actions[0].title', 'required'];
        yield 'action title blank' => [['message' => 'x', 'actions' => [['title' => '  ', 'url' => 'tel:+15550134']]], 'actions[0].title', 'required'];
        yield 'action title long' => [['message' => 'x', 'actions' => [['title' => str_repeat('t', 41), 'url' => 'tel:+15550134']]], 'actions[0].title', 'too_long'];
        yield 'action title line break' => [['message' => 'x', 'actions' => [['title' => "Call\nAna", 'url' => 'tel:+15550134']]], 'actions[0].title', 'invalid_format'];
        yield 'action title type' => [['message' => 'x', 'actions' => [['title' => 42, 'url' => 'tel:+15550134']]], 'actions[0].title', 'invalid_format'];
        yield 'action title utf8' => [['message' => 'x', 'actions' => [['title' => "bad \xFF", 'url' => 'tel:+15550134']]], 'actions[0].title', 'invalid_utf8'];
        yield 'action url missing' => [['message' => 'x', 'actions' => [['title' => 'Call']]], 'actions[0].url', 'required'];
        yield 'action url long' => [['message' => 'x', 'actions' => [['title' => 'Open', 'url' => 'https://example.com/' . str_repeat('a', 2030)]]], 'actions[0].url', 'too_long'];
        yield 'action url http' => [['message' => 'x', 'actions' => [$call, ['title' => 'Open', 'url' => 'http://example.com']]], 'actions[1].url', 'invalid_format'];
        yield 'action url credentials' => [['message' => 'x', 'actions' => [['title' => 'Open', 'url' => 'https://user:pw@example.com']]], 'actions[0].url', 'invalid_format'];
        yield 'action url javascript' => [['message' => 'x', 'actions' => [['title' => 'Run', 'url' => 'javascript:alert(1)']]], 'actions[0].url', 'invalid_format'];
        yield 'action url app scheme' => [['message' => 'x', 'actions' => [['title' => 'Open', 'url' => 'shop://orders/4812']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto empty' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto no address' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana?subject=Hi']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto space' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example?subject=Your quote']]], 'actions[0].url', 'invalid_format'];
        yield 'action tel letters' => [['message' => 'x', 'actions' => [['title' => 'Call', 'url' => 'tel:call-me']]], 'actions[0].url', 'invalid_format'];
        yield 'action tel spaces' => [['message' => 'x', 'actions' => [['title' => 'Call', 'url' => 'tel:+1 555 0134']]], 'actions[0].url', 'invalid_format'];
        yield 'action sms empty' => [['message' => 'x', 'actions' => [['title' => 'Text', 'url' => 'sms:?body=hi']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto without dotted domain' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@localhost']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto two addresses' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example,ion@acme.example']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto cc' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example?cc=boss@acme.example']]], 'actions[0].url', 'invalid_format'];
        yield 'action mailto bad escape' => [['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example?subject=%zz']]], 'actions[0].url', 'invalid_format'];
        yield 'action sms subject' => [['message' => 'x', 'actions' => [['title' => 'Text', 'url' => 'sms:+15550134?subject=Hi']]], 'actions[0].url', 'invalid_format'];
        yield 'action url nbsp' => [['message' => 'x', 'actions' => [['title' => 'Open', 'url' => "https://example.com/a\u{00A0}b"]]], 'actions[0].url', 'invalid_format'];
        yield 'action url blank' => [['message' => 'x', 'actions' => [['title' => 'Call', 'url' => '   ']]], 'actions[0].url', 'required'];
        yield 'action unknown key' => [['message' => 'x', 'actions' => [$call + ['icon' => 'phone']]], 'actions[0].icon', 'not_allowed'];
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

    public function testValidActions(): void
    {
        $urls = [
            'https://shop.example.com:8443/admin/requests/4812?tab=notes#reply',
            'HTTPS://shop.example.com',
            'mailto:ana@acme.example',
            'MailTo:ana.pop+quotes@acme.example?subject=Your%20quote&body=Hi%20Ana%2C',
            'tel:+15550134',
            'TEL:+1-(555)-013.4',
            'tel://+40721000000',
            'sms:+15550134',
            'SMS:0721000000?body=On%20my%20way',
            'mailto:%61na@acme.example?body=a+b&subject=',
            '  tel:+15550134  ',
        ];
        foreach ($urls as $url) {
            $this->assertSame([['title' => 'Open', 'url' => $url]], Wire::build(Message::make('x')->action('Open', $url))['actions'], $url);
        }
        $title = '  ' . str_repeat('é', 39) . '🚀  '; // 40 characters once trimmed
        $this->assertSame($title, Wire::build(Message::make('x')->action($title, 'tel:+15550134'))['actions'][0]['title']);
    }

    public function testMoreThanThreeActionsIsOneErrorLikeOnTheServer(): void
    {
        try {
            Wire::build(Message::fromArray(['message' => 'x', 'actions' => ['a', 'b', ['title' => ''], ['url' => 'ftp://x']]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['actions:too_long'], array_map(static fn ($f) => "{$f->field}:{$f->code}", $e->fields));
        }
    }

    public function testAllActionErrorsAreReportedWithTheirIndex(): void
    {
        try {
            Wire::build(Message::make('x')->actions([
                ['title' => 'Reply', 'url' => 'mailto:ana@acme.example'],
                ['title' => '', 'url' => 'ftp://files.example.com'],
                ['title' => 'Call', 'url' => ''],
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['actions[1].title:required', 'actions[1].url:invalid_format', 'actions[2].url:required'],
                array_map(static fn ($f) => "{$f->field}:{$f->code}", $e->fields),
            );
        }
    }

    public function testActionsAreSentAsGivenWithoutValidation(): void
    {
        $actions = [['title' => 'Run', 'url' => 'javascript:alert(1)']];
        $this->assertSame($actions, Wire::build(Message::fromArray(['message' => 'x', 'actions' => $actions]), [], false)['actions']);
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
        $this->assertSame(
            ['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example'], ['title' => 'Call', 'url' => 'tel:+15550134']]],
            Message::make('x')->action('Reply', 'mailto:ana@acme.example')->action('Call', 'tel:+15550134')->toArray(),
        );
        $this->assertSame([['title' => 'Call', 'url' => 'tel:+15550134']], Message::make('x')->action('Reply', 'mailto:ana@acme.example')->actions([['title' => 'Call', 'url' => 'tel:+15550134']])->actions);
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
