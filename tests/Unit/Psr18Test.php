<?php

declare(strict_types=1);

namespace HonkMe\Tests\Unit;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use HonkMe\Client;
use HonkMe\Exception\AuthException;
use HonkMe\Exception\NetworkException;
use HonkMe\Exception\TimeoutException;
use HonkMe\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

final class Psr18Test extends TestCase
{
    private MockServer $server;

    protected function setUp(): void
    {
        $this->server = MockServer::get();
    }

    private function client(array $guzzle = [], array $args = []): Client
    {
        $factory = new HttpFactory();

        return new Client(...$args + [
            'url' => $this->server->url,
            'key' => ClientTest::KEY,
            'httpClient' => new Guzzle($guzzle + ['timeout' => 2, 'allow_redirects' => false]),
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'backoffBaseMs' => 1,
        ]);
    }

    public function testSendsThroughAnyPsr18ClientAndRetriesWithTheSameKey(): void
    {
        $this->server->script([MockServer::error(503, 'unavailable', [], ['Retry-After' => '0']), MockServer::accepted()]);
        $res = $this->client()->send(['message' => 'via guzzle', 'groupKey' => 'psr/18']);
        $this->assertSame('msg_01k6h3w4z5x6y7z8a9b0c1d2e3', $res->id);
        [$a, $b] = $this->server->requests();
        $this->assertSame('{"message":"via guzzle","group_key":"psr/18"}', $a['body']);
        $this->assertSame($a['headers']['idempotency-key'], $b['headers']['idempotency-key']);
        $this->assertSame('Bearer ' . ClientTest::KEY, $b['headers']['authorization']);
    }

    public function testErrorsAreMappedTheSameWay(): void
    {
        $this->server->script([MockServer::error(401, 'invalid_key')]);
        $this->expectException(AuthException::class);
        $this->client()->send(['message' => 'x']);
    }

    public function testFactoriesAreOptionalWithGuzzleInstalled(): void
    {
        $this->server->script([MockServer::accepted()]);
        $client = new Client(url: $this->server->url, key: ClientTest::KEY, httpClient: new Guzzle());
        $this->assertFalse($client->send(['message' => 'x'])->duplicate);
    }

    public function testConnectionErrorsAreRetriedThenNetworkException(): void
    {
        $port = MockServer::freePort();
        $factory = new HttpFactory();
        $client = new Client(url: "http://127.0.0.1:{$port}", key: ClientTest::KEY, retries: 1, backoffBaseMs: 1, httpClient: new Guzzle(), requestFactory: $factory, streamFactory: $factory);
        try {
            $client->send(['message' => 'x']);
            $this->fail('expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertSame(2, $e->attempts);
        }
    }

    public function testClientTimeoutsBecomeTimeoutException(): void
    {
        $this->server->script([MockServer::accepted() + ['delayMs' => 500]]);
        $this->expectException(TimeoutException::class);
        $this->client(['timeout' => 0.1], ['retries' => 0])->send(['message' => 'x']);
    }
}
