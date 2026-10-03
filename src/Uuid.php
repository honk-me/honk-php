<?php

declare(strict_types=1);

namespace HonkMe;

/** UUIDv7 (RFC 9562) generator, used for automatic idempotency keys. */
final class Uuid
{
    /**
     * A new UUIDv7: 48-bit Unix milliseconds, then random bits. Store it with your job if you
     * want to retry the same event across processes.
     */
    public static function v7(?int $unixMs = null): string
    {
        $ms = $unixMs ?? (int) floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $ms), 2, 6) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
