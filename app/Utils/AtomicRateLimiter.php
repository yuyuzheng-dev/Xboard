<?php

namespace App\Utils;

use Illuminate\Support\Facades\Cache;

/**
 * Atomic fixed-window counter for abuse controls. Requires a cache store that
 * supports atomic increment (Laravel's Redis, database, file and Memcached stores).
 */
final class AtomicRateLimiter
{
    public static function hit(string $key, int $limit, int $decaySeconds): bool
    {
        Cache::add($key, 0, max(1, $decaySeconds));
        $count = Cache::increment($key);

        if (!is_numeric($count)) {
            return false;
        }

        if ((int) $count > max(0, $limit)) {
            // Keep the counter at the limit so denied requests do not extend lockout.
            Cache::decrement($key);
            return false;
        }

        return true;
    }

    public static function release(string $key): void
    {
        Cache::decrement($key);
    }
}
