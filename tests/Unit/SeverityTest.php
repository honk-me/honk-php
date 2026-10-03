<?php

declare(strict_types=1);

namespace HonkMe\Tests\Unit;

use HonkMe\Client;
use HonkMe\Exception\ValidationException;
use HonkMe\Message;
use HonkMe\Severity;
use HonkMe\Tests\Support\MockServer;
use HonkMe\Wire;
use PHPUnit\Framework\TestCase;
use ValueError;

final class SeverityTest extends TestCase
{
    public function testHornNamesAreAliasesOfTheCanonicalCases(): void
    {
        $this->assertSame(Severity::Info, Severity::Light);
        $this->assertSame(Severity::Success, Severity::Beep);
        $this->assertSame(Severity::Warning, Severity::Loud);
        $this->assertSame(Severity::Error, Severity::Long);
        $this->assertSame(Severity::Critical, Severity::Blast);
        $this->assertSame('warning', Severity::Loud->value);
        $this->assertSame('loud', Severity::Warning->horn());
        $this->assertCount(5, Severity::cases());
    }

    public function testParseIsCaseInsensitiveAndAcceptsBothNames(): void
    {
        foreach (['loud' => Severity::Warning, 'LOUD' => Severity::Warning, ' Blast ' => Severity::Critical, 'Beep' => Severity::Success, 'light' => Severity::Info, 'long' => Severity::Error, 'warning' => Severity::Warning, 'ERROR' => Severity::Error] as $in => $want) {
            $this->assertSame($want, Severity::parse($in), $in);
        }
        $this->assertNull(Severity::tryParse('fatal'));
        $this->expectException(ValueError::class);
        Severity::parse('fatal');
    }

    public function testAliasesAreSentCanonical(): void
    {
        $server = MockServer::get();
        $server->script([MockServer::accepted()]);
        $honk = new Client(url: $server->url, key: ClientTest::KEY);
        $honk->send(['message' => 'x', 'severity' => 'loud'], 'k');
        $honk->send(['message' => 'x', 'severity' => 'Warning'], 'k');
        $honk->send(Message::make('x')->severity(Severity::Loud), 'k');
        $honk->send(Message::make('x')->loud(), 'k');
        $honk->send(Message::make('x')->severity('LOUD'), 'k');
        $this->assertSame(array_fill(0, 5, '{"message":"x","severity":"warning"}'), array_column($server->requests(), 'body'));
    }

    public function testAliasesAreNormalizedWithoutValidationToo(): void
    {
        $this->assertSame('critical', Wire::build(Message::make('x')->severity('BLAST'), [], false)['severity']);
        $this->assertSame('fatal', Wire::build(Message::make('x')->severity('fatal'), [], false)['severity']);
    }

    public function testUnknownSeverityNamesTheHonkScale(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('severity must be one of light (info), beep (success), loud (warning), long (error), blast (critical)');
        Wire::build(Message::make('x')->severity('fatal'));
    }

    public function testBuilderAndClientHelpers(): void
    {
        $this->assertSame(
            ['info', 'success', 'warning', 'error', 'critical', 'info', 'critical'],
            array_map(static fn (Message $m) => Wire::build($m)['severity'], [
                Message::make('x')->light(), Message::make('x')->beep(), Message::make('x')->loud(),
                Message::make('x')->long(), Message::make('x')->blast(), Message::make('x')->info(), Message::make('x')->critical(),
            ]),
        );

        $server = MockServer::get();
        $server->script([MockServer::accepted()]);
        $honk = new Client(url: $server->url, key: ClientTest::KEY);
        $honk->light('a', 'm');
        $honk->beep('b', 'm');
        $honk->loud('c', 'm', ['groupKey' => 'disk/var']);
        $honk->long('d', 'm');
        $honk->blast('e', 'm', ['severity' => 'light']);
        $honk->problem('g', 'p', 'm', ['severity' => Severity::Blast]);
        $sent = array_map(static fn ($r) => json_decode($r['body'], true), $server->requests());
        $this->assertSame(['info', 'success', 'warning', 'error', 'critical', 'critical'], array_column($sent, 'severity'));
        $this->assertSame('disk/var', $sent[2]['group_key']);
    }
}
