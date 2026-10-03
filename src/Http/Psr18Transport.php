<?php

declare(strict_types=1);

namespace HonkMe\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Any PSR-18 client with PSR-17 factories. Per-attempt timeouts and redirect behaviour are the
 * client's own configuration (for Guzzle: ['timeout' => 5, 'allow_redirects' => false]); the
 * SDK still enforces its total deadline between attempts.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function post(string $url, array $headers, string $body, int $timeoutMs): Response
    {
        $request = $this->requestFactory->createRequest('POST', $url)->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Our requests are always well-formed, so any client exception (Guzzle also uses
            // RequestExceptionInterface for broken connections) is treated as a transport error.
            $timedOut = (bool) preg_match('/timed? ?out|cURL error 28/i', $e->getMessage());
            throw new TransportException($e->getMessage(), $timedOut, $e);
        }
        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', $values);
        }

        return new Response($response->getStatusCode(), $out, (string) $response->getBody());
    }
}
