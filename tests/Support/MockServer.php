<?php

declare(strict_types=1);

namespace HonkMe\Tests\Support;

use RuntimeException;

/** A scriptable HTTP server (PHP's built-in server with 4 workers) for the unit tests. */
final class MockServer
{
    private static ?self $instance = null;

    /** @var resource */
    private $process;
    public readonly string $url;
    private readonly string $dir;

    private function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/honk-php-mock-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";
        $env = ['HONK_MOCK_DIR' => $this->dir, 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv();
        $this->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']],
            $pipes,
            null,
            $env,
        );
        $this->script([['status' => 202]]);
        for ($i = 0; $i < 100; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);

                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException('mock server did not start');
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            register_shutdown_function(static fn () => self::$instance?->stop());
        }

        return self::$instance;
    }

    /**
     * Sets the answers for the next requests and clears the request log.
     *
     * @param list<array{status?: int, headers?: array<string, string>, body?: mixed, delayMs?: int}> $steps
     */
    public function script(array $steps): void
    {
        file_put_contents($this->dir . '/script.json', json_encode($steps));
        file_put_contents($this->dir . '/count', '0');
        file_put_contents($this->dir . '/requests.jsonl', '');
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string, at: float}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->dir . '/requests.jsonl')));

        return array_values(array_map(static fn ($l) => json_decode($l, true), $lines));
    }

    public function stop(): void
    {
        // PHP_CLI_SERVER_WORKERS forks workers that outlive their parent on SIGTERM.
        $pid = proc_get_status($this->process)['pid'];
        exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null');
        proc_terminate($this->process);
        proc_close($this->process);
    }

    public static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }

    public static function accepted(bool $duplicate = false): array
    {
        return ['status' => 202, 'body' => ['id' => 'msg_01k6h3w4z5x6y7z8a9b0c1d2e3', 'status' => 'accepted', 'duplicate' => $duplicate, 'received_at' => '2026-10-02T21:10:00.123Z']];
    }

    /** @param array<string, mixed> $extra */
    public static function error(int $status, string $code, array $extra = [], array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => ['error' => ['code' => $code, 'message' => "{$code} happened", 'request_id' => 'req_test'] + $extra]];
    }
}
