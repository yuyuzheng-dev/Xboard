<?php

namespace App\Utils;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Lock-backed expiring counter for abuse controls. Cache increments alone are
 * read-modify-write operations on some stores (notably file/array), so protect them.
 */
final class AtomicRateLimiter
{
    private static function lockName(string $key): string
    {
        return 'rate-limit-lock:' . hash('sha256', $key);
    }

    public static function hit(string $key, int $limit, int $decaySeconds): bool
    {
        try {
            return Cache::lock(self::lockName($key), 30)->block(2, function () use ($key, $limit, $decaySeconds): bool {
                $count = (int) Cache::get($key, 0);
                if ($count >= max(0, $limit)) {
                    return false;
                }

                Cache::put($key, $count + 1, max(1, $decaySeconds));
                return true;
            });
        } catch (LockTimeoutException $e) {
            // Fail closed under lock contention rather than allow a rate-limit bypass.
            return false;
        }
    }

    public static function release(string $key): void
    {
        try {
            Cache::lock(self::lockName($key), 30)->block(2, function () use ($key): void {
                if ((int) Cache::get($key, 0) > 0) {
                    Cache::decrement($key);
                }
            });
        } catch (LockTimeoutException $e) {
            // A failed validation may temporarily consume a slot if the cache is contended.
        }
    }
}
