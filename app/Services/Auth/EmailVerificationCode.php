<?php

namespace App\Services\Auth;

use App\Utils\CacheKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class EmailVerificationCode
{
    public const TTL_SECONDS = 300;
    public const REGISTER_MAX_FAILURES = 5;
    public const REGISTER_LOCKOUT_SECONDS = 900;

    private static function normalized(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function codeKey(string $email): string
    {
        return CacheKey::get('EMAIL_VERIFY_CODE', self::normalized($email));
    }

    private static function purposeKey(string $email): string
    {
        return CacheKey::get('EMAIL_VERIFY_PURPOSE', hash('sha256', self::normalized($email)));
    }

    public static function registrationFailuresKey(string $email): string
    {
        return CacheKey::get('EMAIL_VERIFY_REGISTER_ATTEMPTS', hash('sha256', self::normalized($email)));
    }

    private static function locked(string $email, callable $callback): mixed
    {
        return Cache::lock('email-verify-lock:' . hash('sha256', self::normalized($email)), 15)
            ->block(2, $callback);
    }

    /**
     * Keep registration failure budgets across resends and source IP changes.
     * Password resets use their own existing rate limiter and cannot use registration codes.
     */
    public static function issue(string $email, string $purpose, string $code): bool
    {
        if (!in_array($purpose, ['register', 'reset'], true)) {
            throw new \InvalidArgumentException('Invalid OTP purpose');
        }
        try {
            return self::locked($email, function () use ($email, $purpose, $code): bool {
                if ($purpose === 'register'
                    && (int) Cache::get(self::registrationFailuresKey($email), 0) >= self::REGISTER_MAX_FAILURES) {
                    return false;
                }
                Cache::put(self::codeKey($email), $code, self::TTL_SECONDS);
                Cache::put(self::purposeKey($email), $purpose, self::TTL_SECONDS);
                return true;
            });
        } catch (LockTimeoutException $e) {
            return false;
        }
    }

    /** Returns "valid", "invalid", or "blocked". */
    public static function verify(string $email, string $purpose, string $candidate): string
    {
        try {
            return self::locked($email, function () use ($email, $purpose, $candidate): string {
                $failureKey = self::registrationFailuresKey($email);
                if ($purpose === 'register'
                    && (int) Cache::get($failureKey, 0) >= self::REGISTER_MAX_FAILURES) {
                    return 'blocked';
                }

                $issuedPurpose = Cache::get(self::purposeKey($email));
                $issuedCode = Cache::get(self::codeKey($email));
                if ($issuedCode === null || $issuedPurpose !== $purpose) {
                    return 'invalid';
                }
                if (hash_equals((string) $issuedCode, $candidate)) {
                    return 'valid';
                }

                if ($purpose === 'register') {
                    $failures = (int) Cache::get($failureKey, 0) + 1;
                    Cache::put($failureKey, $failures, self::REGISTER_LOCKOUT_SECONDS);
                    if ($failures >= self::REGISTER_MAX_FAILURES) {
                        Cache::forget(self::codeKey($email));
                        Cache::forget(self::purposeKey($email));
                        return 'blocked';
                    }
                }
                return 'invalid';
            });
        } catch (LockTimeoutException $e) {
            return 'blocked';
        }
    }

    public static function consume(string $email, string $purpose): void
    {
        self::locked($email, function () use ($email, $purpose): void {
            if (Cache::get(self::purposeKey($email)) !== $purpose) {
                return;
            }
            Cache::forget(self::codeKey($email));
            Cache::forget(self::purposeKey($email));
            if ($purpose === 'register') {
                Cache::forget(self::registrationFailuresKey($email));
            }
        });
    }

    public static function discard(string $email, string $purpose, string $code): void
    {
        self::locked($email, function () use ($email, $purpose, $code): void {
            if (Cache::get(self::purposeKey($email)) === $purpose
                && hash_equals((string) Cache::get(self::codeKey($email), ''), $code)) {
                Cache::forget(self::codeKey($email));
                Cache::forget(self::purposeKey($email));
            }
        });
    }
}
