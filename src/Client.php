<?php

declare(strict_types=1);

namespace HonkMe;

use DateTimeImmutable;
use HonkMe\Exception\AuthException;
use HonkMe\Exception\ConflictException;
use HonkMe\Exception\HonkException;
use HonkMe\Exception\NetworkException;
use HonkMe\Exception\QuotaException;
use HonkMe\Exception\ServerException;
use HonkMe\Exception\TimeoutException;
use HonkMe\Exception\ValidationException;
use HonkMe\Http\CurlTransport;
use HonkMe\Http\Psr18Transport;
use HonkMe\Http\Response;
use HonkMe\Http\Transport;
use HonkMe\Http\TransportException;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Client for POST /v1/messages. Create one per process and reuse it (the connection is kept
 * alive). Framework-agnostic; Laravel users get it through the Honk facade.
 *
 *     $honk = new Client(url: getenv('HONK_URL'), key: getenv('HONK_KEY'));
 *     $honk->send(Message::make('nightly pg_dump took 42 s')->title('Backup finished')->success());
 */
final class Client
{
    public const VERSION = '0.1.0';

    private readonly string $url;
    private readonly string $key;
    private readonly Transport $transport;
    private readonly string $userAgent;
    /** @var array{source?: ?string, environment?: ?string, channel?: ?string} */
    private readonly array $defaults;

    /**
     * @param string                                                         $url            base address of your Honk server, e.g. https://honk.example.com
     * @param string                                                         $key            project ingestion key honk_… (server-side only)
     * @param int                                                            $timeoutMs      timeout of one HTTP attempt
     * @param int                                                            $retries        retries after the first attempt (network errors, 429 and 5xx only)
     * @param int                                                            $deadlineMs     total time budget of one send(), waits included
     * @param array{source?: ?string, environment?: ?string, channel?: ?string} $defaults    applied when a message leaves these fields unset
     * @param bool                                                           $validate       validate locally before sending (the server always validates)
     * @param int                                                            $backoffBaseMs  backoff: attempt n waits rand(0, min(max, base·2ⁿ)) or Retry-After when longer
     * @param ClientInterface|null                                           $httpClient     any PSR-18 client (default: ext-curl)
     */
    public function __construct(
        string $url,
        string $key,
        public readonly int $timeoutMs = 5000,
        public readonly int $retries = 4,
        public readonly int $deadlineMs = 30000,
        array $defaults = [],
        public readonly bool $validate = true,
        private readonly int $backoffBaseMs = 500,
        private readonly int $backoffMaxMs = 8000,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $userAgent = null,
        ?Transport $transport = null,
    ) {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            throw new InvalidArgumentException('Honk: url is required (the base address of your Honk server, e.g. https://honk.example.com; is HONK_URL set?)');
        }
        if (preg_match('~^https?://[^/]+~i', $url) !== 1) {
            throw new InvalidArgumentException(sprintf('Honk: url must start with https:// (got "%s")', $url));
        }
        $this->url = (string) preg_replace('~/v1/messages$~', '', $url);
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Honk: key is required (a project ingestion key honk_…; is HONK_KEY set?)');
        }
        if (preg_match('/^honk_[\x21-\x7E]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Honk: key must be a project ingestion key starting with honk_ (create one under Project → Keys)');
        }
        $this->key = $key;
        if ($timeoutMs <= 0 || $deadlineMs <= 0 || $retries < 0 || $backoffBaseMs <= 0 || $backoffMaxMs <= 0) {
            throw new InvalidArgumentException('Honk: timeoutMs, deadlineMs and backoff must be positive, retries ≥ 0');
        }
        $this->defaults = array_intersect_key($defaults, ['source' => 1, 'environment' => 1, 'channel' => 1]);
        $this->userAgent = 'honk-me-php/' . self::VERSION . ($userAgent ? ' ' . $userAgent : '');
        $this->transport = $transport ?? self::makeTransport($httpClient, $requestFactory, $streamFactory);
    }

    /**
     * Reads HONK_URL, HONK_KEY and optionally HONK_SOURCE, HONK_ENVIRONMENT, HONK_CHANNEL.
     *
     * @param array{url?: string, key?: string, timeoutMs?: int, retries?: int, deadlineMs?: int, defaults?: array{source?: ?string, environment?: ?string, channel?: ?string}, validate?: bool, httpClient?: ClientInterface, requestFactory?: RequestFactoryInterface, streamFactory?: StreamFactoryInterface, userAgent?: string} $options
     *        named constructor arguments that override the environment
     */
    public static function fromEnv(array $options = []): self
    {
        $env = static function (string $name): ?string {
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            return is_string($v) && $v !== '' ? $v : null;
        };
        $defaults = array_filter([
            'source' => $env('HONK_SOURCE'),
            'environment' => $env('HONK_ENVIRONMENT'),
            'channel' => $env('HONK_CHANNEL'),
        ]);

        return new self(
            url: $options['url'] ?? $env('HONK_URL') ?? '',
            key: $options['key'] ?? $env('HONK_KEY') ?? '',
            timeoutMs: $options['timeoutMs'] ?? 5000,
            retries: $options['retries'] ?? 4,
            deadlineMs: $options['deadlineMs'] ?? 30000,
            defaults: ($options['defaults'] ?? []) + $defaults,
            validate: $options['validate'] ?? true,
            httpClient: $options['httpClient'] ?? null,
            requestFactory: $options['requestFactory'] ?? null,
            streamFactory: $options['streamFactory'] ?? null,
            userAgent: $options['userAgent'] ?? null,
        );
    }

    /**
     * Sends one event. Returns once Honk has durably stored it (202), which does not mean a push
     * was delivered. Network errors, 429 and 5xx are retried with the same Idempotency-Key until
     * $retries or $deadlineMs runs out.
     *
     * @param Message|array<string, mixed> $message      a Message or camelCase fields
     * @param string|null                  $idempotencyKey stable key for this event (default: the message's, else a new UUIDv7)
     *
     * @throws HonkException (ValidationException, AuthException, QuotaException, ConflictException, NetworkException, TimeoutException, ServerException)
     */
    public function send(Message|array $message, ?string $idempotencyKey = null): Accepted
    {
        $message = is_array($message) ? Message::fromArray($message) : $message;
        $body = Wire::encode(Wire::build($message, $this->defaults, $this->validate));
        $key = $idempotencyKey ?? $message->idempotencyKey;
        $key = $key === null ? Uuid::v7() : Wire::checkIdempotencyKey($key);
        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Idempotency-Key' => $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        $deadline = self::nowMs() + $this->deadlineMs;
        for ($attempt = 1; ; $attempt++) {
            $timeout = (int) max(1, min($this->timeoutMs, $deadline - self::nowMs()));
            try {
                $response = $this->transport->post($this->url . '/v1/messages', $headers, $body, $timeout);
                if ($response->status >= 200 && $response->status < 300) {
                    return self::accepted($response, $key, $attempt);
                }
                $failure = self::errorFor($response, $key, $attempt);
            } catch (TransportException $e) {
                $failure = $e->timedOut
                    ? new TimeoutException("Honk did not answer within {$timeout} ms (the event may or may not have been stored; retrying with the same idempotency key is safe)", null, 'timeout', null, $key, $attempt, null, null, $e)
                    : new NetworkException('Could not reach Honk: ' . $e->getMessage(), null, 'network_error', null, $key, $attempt, null, null, $e);
            }
            if (!$failure->isRetryable() || $attempt > $this->retries) {
                throw $failure;
            }
            $ceiling = min($this->backoffMaxMs, $this->backoffBaseMs * (2 ** min($attempt - 1, 30)));
            $waitMs = max(random_int(0, (int) $ceiling), ($failure->retryAfter ?? 0) * 1000);
            if (self::nowMs() + $waitMs >= $deadline) {
                throw $failure;
            }
            usleep($waitMs * 1000);
        }
    }

    /**
     * A problem for $groupKey (opens or continues its incident). Severity defaults to long (error).
     *
     * @param array<string, mixed> $options message fields in camelCase, plus 'idempotencyKey'
     */
    public function problem(string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::problem($groupKey, $title, $message, $options));
    }

    /**
     * A recovery for $groupKey (closes its open incident). Severity defaults to beep (success).
     *
     * @param array<string, mixed> $options
     */
    public function recovery(string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::recovery($groupKey, $title, $message, $options));
    }

    /**
     * A light honk (severity info).
     *
     * @param array<string, mixed> $options
     */
    public function light(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('info', $title, $message, $options));
    }

    /**
     * A beep-beep (severity success).
     *
     * @param array<string, mixed> $options
     */
    public function beep(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('success', $title, $message, $options));
    }

    /**
     * A loud honk (severity warning).
     *
     * @param array<string, mixed> $options
     */
    public function loud(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('warning', $title, $message, $options));
    }

    /**
     * A long honk (severity error; pushes at least as high priority).
     *
     * @param array<string, mixed> $options
     */
    public function long(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('error', $title, $message, $options));
    }

    /**
     * A blast (severity critical; pushes at least as high priority).
     *
     * @param array<string, mixed> $options
     */
    public function blast(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('critical', $title, $message, $options));
    }

    /**
     * Synonym of light().
     *
     * @param array<string, mixed> $options
     */
    public function info(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('info', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function success(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('success', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function warning(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('warning', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function error(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('error', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function critical(?string $title, string $message, array $options = []): Accepted
    {
        return $this->sendHelper(Helper::severity('critical', $title, $message, $options));
    }

    /**
     * The wire body (snake_case) that send() would post, with defaults applied and validated.
     *
     * @param Message|array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function body(Message|array $message): array
    {
        return Wire::build(is_array($message) ? Message::fromArray($message) : $message, $this->defaults, $this->validate);
    }

    private function sendHelper(Message $message): Accepted
    {
        return $this->send($message);
    }

    private static function makeTransport(?ClientInterface $client, ?RequestFactoryInterface $requests, ?StreamFactoryInterface $streams): Transport
    {
        if ($client !== null) {
            if ($requests === null || $streams === null) {
                if (!class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
                    throw new InvalidArgumentException('Honk: with a PSR-18 httpClient, also pass requestFactory and streamFactory (PSR-17)');
                }
                $factory = new \GuzzleHttp\Psr7\HttpFactory();
                $requests ??= $factory;
                $streams ??= $factory;
            }

            return new Psr18Transport($client, $requests, $streams);
        }
        if (!function_exists('curl_init')) {
            throw new InvalidArgumentException('Honk: ext-curl is not installed; enable it or pass a PSR-18 httpClient');
        }

        return new CurlTransport();
    }

    private static function accepted(Response $response, string $key, int $attempts): Accepted
    {
        $data = json_decode($response->body, true);
        if (!is_array($data) || !is_string($data['id'] ?? null)) {
            throw new HonkException("Honk answered {$response->status} without a message id", $response->status, null, null, $key, $attempts, null, $response->body);
        }
        try {
            $receivedAt = new DateTimeImmutable(is_string($data['received_at'] ?? null) ? $data['received_at'] : 'now');
        } catch (\Exception) {
            $receivedAt = new DateTimeImmutable();
        }

        return new Accepted($data['id'], ($data['duplicate'] ?? false) === true, $receivedAt);
    }

    private static function errorFor(Response $response, string $key, int $attempts): HonkException
    {
        $data = json_decode($response->body, true);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $detail = is_string($error['message'] ?? null) ? $error['message'] : (trim(substr($response->body, 0, 200)) ?: "HTTP {$response->status}");
        $requestId = is_string($error['request_id'] ?? null) ? $error['request_id'] : $response->header('x-request-id');
        $retryAfter = self::retryAfter($response->header('retry-after'));
        $body = is_array($data) ? $data : ($response->body === '' ? null : $response->body);
        $status = $response->status;
        $label = "Honk {$status}" . ($code ? " {$code}" : '') . ": {$detail}";

        if (in_array($status, [400, 413, 415, 422], true)) {
            $fields = [];
            foreach (is_array($error['fields'] ?? null) ? $error['fields'] : [] as $f) {
                if (is_array($f) && is_string($f['field'] ?? null) && is_string($f['code'] ?? null)) {
                    $fields[] = new FieldError($f['field'], $f['code'], is_string($f['message'] ?? null) ? $f['message'] : null);
                }
            }
            $list = implode('; ', array_map(static fn (FieldError $f) => $f->field . ' ' . ($f->message ?? $f->code), $fields));

            return new ValidationException($list === '' ? $label : "{$label} ({$list})", $fields, false, $status, $code, $requestId, $key, $attempts, $body);
        }
        $args = [$label, $status, $code, $requestId, $key, $attempts, $retryAfter, $body];

        return match (true) {
            $status === 401, $status === 403 => new AuthException(...$args),
            $status === 409 => new ConflictException(...$args),
            $status === 429 => new QuotaException(...$args),
            $status >= 500 => new ServerException(...$args),
            $status >= 300 && $status < 400 => new HonkException(
                "Honk answered {$status} redirect" . (($to = $response->header('location')) ? " to {$to}" : '') . '; set url to the final https address',
                ...array_slice($args, 1),
            ),
            $status === 404 => new HonkException("{$label} (is url the base address of your Honk server?)", ...array_slice($args, 1)),
            default => new HonkException(...$args),
        };
    }

    /** Seconds from a Retry-After header (delta-seconds or HTTP date). */
    public static function retryAfter(?string $value, ?int $now = null): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $at = strtotime($value);

        return $at === false ? null : max(0, $at - ($now ?? time()));
    }

    private static function nowMs(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
