<?php

declare(strict_types=1);

namespace HonkMe\Http;

/** Sends one POST. Implementations never follow redirects. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException when no HTTP answer was received
     */
    public function post(string $url, array $headers, string $body, int $timeoutMs): Response;
}
