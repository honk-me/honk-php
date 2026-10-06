<?php

declare(strict_types=1);

namespace HonkMe;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HonkMe\Exception\ValidationException;

/**
 * Turns a Message into the JSON body of POST /v1/messages and applies the cheap checks the
 * server would apply anyway (limits from contracts/openapi.yaml).
 *
 * @internal
 */
final class Wire
{
    public const MAX_BODY_BYTES = 16384;
    public const MAX_MESSAGE_BYTES = 8192;
    public const MAX_URL_BYTES = 2048;
    public const MAX_ACTIONS = 3;
    public const MAX_ACTION_TITLE = 40;

    public const SEVERITIES = ['info', 'success', 'warning', 'error', 'critical'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const EVENT_TYPES = ['event', 'problem', 'recovery'];
    public const CATEGORIES = ['infrastructure', 'security', 'backups', 'deployments', 'payments', 'customers', 'sales', 'automation', 'personal', 'other'];

    private const SHORT_TEXT = ['title' => 160, 'source' => 64, 'environment' => 32, 'channel' => 64, 'group_key' => 128];
    // Go's unicode.IsControl (C0, DEL, C1) plus U+2028/U+2029, as on the server.
    private const CONTROL = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u';
    private const CONTROL_EXCEPT_BREAKS = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u';
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';
    // Unicode White_Space (Go's unicode.IsSpace, as on the server), for action URLs and titles.
    private const SPACE = '\s\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';
    // The number of a tel: or sms: action: an optional leading +, digits and - . ( ) separators.
    private const PHONE_NUMBER = '/^\+?[0-9().-]*[0-9][0-9().-]*$/D';
    // A single plain mailto: address, once percent-decoded: dot-atom@dot-atom with a dotted domain
    // (or an IPv4 literal).
    private const ATOM = '[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~\-\x{80}-\x{10FFFF}]+';
    private const MAIL_ADDRESS = '/^' . self::ATOM . '(\.' . self::ATOM . ')*@(' . self::ATOM . '(\.' . self::ATOM . ')+|\[[0-9.]*\.[0-9.]*\])$/uD';
    private const BAD_ESCAPE = '/%(?![0-9A-Fa-f]{2})/';

    /**
     * @param array{source?: ?string, environment?: ?string, channel?: ?string} $defaults
     *
     * @return array<string, mixed> wire fields (snake_case)
     *
     * @throws ValidationException listing every invalid field
     */
    public static function build(Message $message, array $defaults = [], bool $validate = true): array
    {
        $body = [];
        foreach (Message::FIELDS as $field => $wire) {
            $value = $message->{$field};
            if (($value === null || $value === '') && in_array($field, ['source', 'environment', 'channel'], true)) {
                $value = $defaults[$field] ?? null;
            }
            if ($value === null || $value === '' || ($field === 'actions' && $value === [])) {
                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $value = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
            }
            // Horn names (any case) become the canonical value, even without validation, so the
            // idempotency payload is canonical and older servers understand it.
            if ($value instanceof Severity) {
                $value = $value->value;
            } elseif ($field === 'severity' && is_string($value) && ($parsed = Severity::tryParse($value)) !== null) {
                $value = $parsed->value;
            }
            $body[$wire] = $value;
        }

        $errors = [];
        if ($validate) {
            self::check($body, $errors);
        } elseif (!isset($body['message'])) {
            $errors[] = new FieldError('message', 'required', 'message is required');
        }
        if ($errors === []) {
            $size = strlen(self::encode($body, $errors));
            if ($size > self::MAX_BODY_BYTES) {
                $errors[] = new FieldError('body', 'too_long', "the JSON body is {$size} bytes; Honk accepts at most 16 KiB");
            }
        }
        if ($errors !== []) {
            throw ValidationException::local($errors);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    public static function encode(array $body, array &$errors = []): string
    {
        if (isset($body['metadata']) && is_array($body['metadata'])) {
            $body['metadata'] = (object) $body['metadata'];
        }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            $errors[] = new FieldError('body', 'invalid_format', 'cannot be encoded as JSON: ' . json_last_error_msg());

            return '';
        }

        return $json;
    }

    /** Checks an Idempotency-Key: 1–128 printable ASCII characters (0x21–0x7E). */
    public static function checkIdempotencyKey(string $key): string
    {
        if (preg_match('/^[\x21-\x7E]{1,128}$/', $key) !== 1) {
            throw ValidationException::local([new FieldError('Idempotency-Key', 'invalid_format', 'use 1-128 printable ASCII characters without spaces, e.g. "request-4812"')]);
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $b
     * @param list<FieldError>     $e
     */
    private static function check(array $b, array &$e): void
    {
        $m = $b['message'] ?? null;
        if ($m === null) {
            $e[] = new FieldError('message', 'required', 'message is required');
        } elseif (!is_string($m)) {
            $e[] = new FieldError('message', 'invalid_format', 'must be a string');
        } elseif (!self::utf8($m)) {
            $e[] = new FieldError('message', 'invalid_utf8', 'must be valid UTF-8');
        } elseif (strlen($m) > self::MAX_MESSAGE_BYTES) {
            $e[] = new FieldError('message', 'too_long', 'must be at most ' . self::MAX_MESSAGE_BYTES . ' bytes of UTF-8 (got ' . strlen($m) . ')');
        } elseif (trim($m) === '') {
            $e[] = new FieldError('message', 'too_short', 'must not be blank');
        } elseif (preg_match(self::CONTROL_EXCEPT_BREAKS, $m) === 1) {
            $e[] = new FieldError('message', 'invalid_format', 'must not contain control characters other than line breaks and tabs');
        }

        foreach (self::SHORT_TEXT as $field => $max) {
            if (!isset($b[$field])) {
                continue;
            }
            $v = $b[$field];
            if (!is_string($v)) {
                $e[] = new FieldError($field, 'invalid_format', 'must be a string');
            } elseif (!self::utf8($v)) {
                $e[] = new FieldError($field, 'invalid_utf8', 'must be valid UTF-8');
            } elseif (($s = trim($v)) === '') {
                $e[] = new FieldError($field, 'too_short', 'must not be empty');
            } elseif (self::length($s) > $max) {
                $e[] = new FieldError($field, 'too_long', "must be at most {$max} characters");
            } elseif (preg_match(self::CONTROL, $s) === 1) {
                $e[] = new FieldError($field, 'invalid_format', 'must not contain control characters or line breaks');
            }
        }

        if (isset($b['severity']) && !in_array($b['severity'], self::SEVERITIES, true)) {
            $e[] = new FieldError('severity', 'invalid_enum', 'must be one of light (info), beep (success), loud (warning), long (error), blast (critical)');
        }
        foreach (['priority' => self::PRIORITIES, 'event_type' => self::EVENT_TYPES, 'category' => self::CATEGORIES] as $field => $allowed) {
            if (isset($b[$field]) && !in_array($b[$field], $allowed, true)) {
                $e[] = new FieldError($field, 'invalid_enum', 'must be one of ' . implode(', ', $allowed));
            }
        }

        if (isset($b['occurred_at']) && (!is_string($b['occurred_at']) || preg_match(self::RFC3339, trim($b['occurred_at'])) !== 1)) {
            $e[] = new FieldError('occurred_at', 'invalid_format', 'must be a DateTimeInterface or an RFC 3339 timestamp like 2026-10-01T21:10:00Z');
        }

        $seq = $b['source_sequence'] ?? null;
        if ($seq !== null && (!is_int($seq) || $seq < 0 || $seq > 9007199254740991)) {
            $e[] = new FieldError('source_sequence', 'out_of_range', 'must be an integer between 0 and 2^53-1');
        }
        if (!isset($b['group_key'])) {
            if (($b['event_type'] ?? null) === 'recovery') {
                $e[] = new FieldError('group_key', 'requires_group_key', 'recovery events require group_key');
            }
            if ($seq !== null) {
                $e[] = new FieldError('source_sequence', 'requires_group_key', 'source_sequence requires group_key');
            }
        }

        if (isset($b['url']) && !self::validUrl($b['url'], false)) {
            $e[] = new FieldError('url', 'invalid_format', 'must be an https URL without credentials, at most 2048 bytes');
        }
        if (isset($b['image_url']) && !self::validUrl($b['image_url'], true)) {
            $e[] = new FieldError('image_url', 'invalid_format', 'must be an https URL without credentials or fragment, at most 2048 bytes');
        }
        if (isset($b['actions'])) {
            self::checkActions($b['actions'], $e);
        }

        if (isset($b['metadata'])) {
            $md = $b['metadata'];
            if (!is_array($md)) {
                $e[] = new FieldError('metadata', 'invalid_format', 'must be an array of strings, numbers and booleans');
            } else {
                if (count($md) > 16) {
                    $e[] = new FieldError('metadata', 'too_long', 'at most 16 keys');
                }
                foreach ($md as $k => $v) {
                    $field = 'metadata.' . $k;
                    if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) $k) !== 1) {
                        $e[] = new FieldError($field, 'invalid_format', 'keys must match [A-Za-z0-9_.-]{1,64}');
                    } elseif (is_string($v)) {
                        if (!self::utf8($v) || self::length($v) > 512 || preg_match(self::CONTROL_EXCEPT_BREAKS, $v) === 1) {
                            $e[] = new FieldError($field, 'invalid_format', 'strings must be valid UTF-8, at most 512 characters, without control characters');
                        }
                    } elseif (is_float($v)) {
                        if (!is_finite($v)) {
                            $e[] = new FieldError($field, 'invalid_format', 'numbers must be finite');
                        }
                    } elseif (!is_int($v) && !is_bool($v)) {
                        $e[] = new FieldError($field, 'invalid_format', 'values must be strings, numbers or booleans (got ' . get_debug_type($v) . ')');
                    }
                }
            }
        }

        $ttl = $b['ttl_seconds'] ?? null;
        if ($ttl !== null && (!is_int($ttl) || $ttl < 60 || $ttl > 86400)) {
            $e[] = new FieldError('ttl_seconds', 'out_of_range', 'must be an integer between 60 and 86400');
        }
    }

    /** @param list<FieldError> $e */
    private static function checkActions(mixed $actions, array &$e): void
    {
        $format = "must be a list of at most 3 ['title' => …, 'url' => …]";
        if (!is_array($actions) || !array_is_list($actions)) {
            $e[] = new FieldError('actions', 'invalid_format', $format);

            return;
        }
        if (count($actions) > self::MAX_ACTIONS) {
            $e[] = new FieldError('actions', 'too_long', 'at most ' . self::MAX_ACTIONS . ' actions');

            return;
        }
        $list = [];
        foreach ($actions as $a) {
            if (!is_array($a) || ($a !== [] && array_is_list($a))) {
                $e[] = new FieldError('actions', 'invalid_format', $format);

                return;
            }
            $list[] = $a;
        }
        foreach ($list as $i => $a) {
            $field = "actions[{$i}]";
            $title = is_string($a['title'] ?? null) ? self::trimSpace($a['title']) : $a['title'] ?? null;
            if ($title === null || $title === '') {
                $e[] = new FieldError("{$field}.title", 'required', 'title is required');
            } elseif (!is_string($title)) {
                $e[] = new FieldError("{$field}.title", 'invalid_format', 'must be a string');
            } elseif (!self::utf8($title)) {
                $e[] = new FieldError("{$field}.title", 'invalid_utf8', 'must be valid UTF-8');
            } elseif (self::length($title) > self::MAX_ACTION_TITLE) {
                $e[] = new FieldError("{$field}.title", 'too_long', 'must be at most ' . self::MAX_ACTION_TITLE . ' characters');
            } elseif (preg_match(self::CONTROL, $title) === 1) {
                $e[] = new FieldError("{$field}.title", 'invalid_format', 'must be one line without control characters');
            }

            $url = is_string($a['url'] ?? null) ? self::trimSpace($a['url']) : $a['url'] ?? null;
            if ($url === null || $url === '') {
                $e[] = new FieldError("{$field}.url", 'required', 'url is required');
            } elseif (!is_string($url)) {
                $e[] = new FieldError("{$field}.url", 'invalid_format', 'must be a string');
            } elseif (!self::utf8($url)) {
                $e[] = new FieldError("{$field}.url", 'invalid_utf8', 'must be valid UTF-8');
            } elseif (strlen($url) > self::MAX_URL_BYTES) {
                $e[] = new FieldError("{$field}.url", 'too_long', 'must be at most ' . self::MAX_URL_BYTES . ' bytes');
            } elseif (!self::validActionUrl($url)) {
                $e[] = new FieldError("{$field}.url", 'invalid_format', 'must be an https://, mailto:, tel: or sms: URL without spaces');
            }

            $keys = array_map('strval', array_keys($a));
            sort($keys);
            foreach ($keys as $key) {
                if ($key !== 'title' && $key !== 'url') {
                    $e[] = new FieldError("{$field}.{$key}", 'not_allowed', 'unknown field (an action has title and url)');
                }
            }
        }
    }

    /**
     * The server's check of a (trimmed, valid UTF-8) action URL, scheme in any case: https:// with
     * a host and no credentials (as url), mailto: with one address and an optional
     * ?subject=…&body=…, tel: / tel:// with a number, sms: with a number and an optional ?body=….
     */
    private static function validActionUrl(string $s): bool
    {
        if (preg_match(self::CONTROL, $s) === 1 || preg_match('/[' . self::SPACE . ']/u', $s) === 1 || ($colon = strpos($s, ':')) === false) {
            return false;
        }
        $rest = substr($s, $colon + 1);
        [$head, $query] = explode('?', $rest, 2) + [1 => ''];

        return match (strtolower(substr($s, 0, $colon))) {
            'https' => self::validUrl($s, false),
            'mailto' => preg_match(self::BAD_ESCAPE, $head) !== 1 && preg_match(self::MAIL_ADDRESS, rawurldecode($head)) === 1 && self::actionQuery($query, ['subject', 'body']),
            'tel' => preg_match(self::PHONE_NUMBER, str_starts_with($rest, '//') ? substr($rest, 2) : $rest) === 1,
            'sms' => preg_match(self::PHONE_NUMBER, $head) === 1 && self::actionQuery($query, ['body']),
            default => false,
        };
    }

    /**
     * The query of a mailto: or sms: action: valid percent-encoding, no ";" separators and only
     * the allowed keys ("" is no query).
     *
     * @param list<string> $allowed
     */
    private static function actionQuery(string $query, array $allowed): bool
    {
        foreach (explode('&', $query) as $pair) {
            if (str_contains($pair, ';') || preg_match(self::BAD_ESCAPE, $pair) === 1) {
                return false;
            }
            if ($pair !== '' && !in_array(urldecode(explode('=', $pair, 2)[0]), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** Trims Unicode White_Space, like the server's strings.TrimSpace (invalid UTF-8 is kept as is). */
    private static function trimSpace(string $s): string
    {
        return preg_replace('/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/uD', '', $s) ?? $s;
    }

    /** The server's syntactic URL check: https, a host, no credentials, ≤ 2048 bytes. */
    public static function validUrl(mixed $raw, bool $image): bool
    {
        if (!is_string($raw)) {
            return false;
        }
        $s = trim($raw);
        if ($s === '' || strlen($s) > self::MAX_URL_BYTES || !self::utf8($s) || preg_match(self::CONTROL, $s) === 1 || strpbrk($s, ' \\') !== false) {
            return false;
        }
        if (stripos($s, 'https://') !== 0 || ($image && str_contains($s, '#'))) {
            return false;
        }
        $parts = preg_split('~[/?#]~', substr($s, 8), 2);
        $authority = $parts === false ? '' : $parts[0];
        if ($authority === '' || str_contains($authority, '@')) {
            return false;
        }
        if (preg_match('/^(\[[^\]]*\]|[^:\[\]]*)(?::(\d*))?$/', $authority, $m) !== 1 || $m[1] === '' || $m[1] === '[]') {
            return false;
        }
        $port = $m[2] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535 || strlen($port) > 5)) {
            return false;
        }

        return true;
    }

    private static function utf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    private static function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./su', $s);
    }
}
