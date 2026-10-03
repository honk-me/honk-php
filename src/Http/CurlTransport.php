<?php

declare(strict_types=1);

namespace HonkMe\Http;

use CurlHandle;

/**
 * Default transport (ext-curl). One handle per client, so the connection is kept alive between
 * sends in long-running processes such as queue workers.
 */
final class CurlTransport implements Transport
{
    private ?CurlHandle $handle = null;

    public function post(string $url, array $headers, string $body, int $timeoutMs): Response
    {
        if ($url === '') {
            throw new TransportException('empty URL');
        }
        $this->handle ??= curl_init() ?: throw new TransportException('curl_init failed');
        $ch = $this->handle;
        curl_reset($ch); // keeps the connection cache

        $lines = ['Expect:']; // no 100-continue round trip
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, min($timeoutMs, 5000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (e.g. after 100 Continue)
                }

                return strlen($line);
            },
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            $errno = curl_errno($ch);
            throw new TransportException(sprintf('curl error %d: %s', $errno, curl_error($ch)), $errno === CURLE_OPERATION_TIMEDOUT);
        }

        return new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $responseHeaders, (string) $result);
    }
}
