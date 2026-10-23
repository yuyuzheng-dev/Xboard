<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Utils\AtomicRateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class LoginService
{
    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password): array
    {
        // Reserve the attempt atomically before password verification so parallel requests
        // cannot all pass the same stale counter value.
        $passwordLimitKey = null;
        if ((int) admin_setting('password_limit_enable', true)) {
            $passwordLimitKey = CacheKey::get('PASSWORD_ERROR_LIMIT', $email);
            $limit = (int) admin_setting('password_limit_count', 5);
            $expireSeconds = max(1, (int) admin_setting('password_limit_expire', 60)) * 60;
            if (!AtomicRateLimiter::hit($passwordLimitKey, $limit, $expireSeconds)) {
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => admin_setting('password_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        if ($passwordLimitKey !== null) {
            Cache::forget($passwordLimitKey);
        }

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        // Count reset attempts atomically. A valid code is rechecked while holding the user row lock.
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        if (!AtomicRateLimiter::hit($forgetRequestLimitKey, 3, 300)) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        $emailCodeKey = CacheKey::get('EMAIL_VERIFY_CODE', $email);
        $cachedEmailCode = Cache::get($emailCodeKey);
        if ($cachedEmailCode === null || !hash_equals((string) $cachedEmailCode, $emailCode)) {
            return [false, [400, __('Incorrect email verification code')]];
        }

        // Keep the existing generic code error before checking account existence.
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('This email is not registered in the system')]];
        }

        $result = DB::transaction(function () use ($user, $email, $emailCode, $emailCodeKey, $password) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser) {
                return [false, 'missing'];
            }

            // Another concurrent reset may have consumed the same code while this request waited.
            $currentCode = Cache::get($emailCodeKey);
            if ($currentCode === null || !hash_equals((string) $currentCode, $emailCode)) {
                return [false, 'code'];
            }

            $lockedUser->password = password_hash($password, PASSWORD_DEFAULT);
            $lockedUser->password_algo = null;
            $lockedUser->password_salt = null;
            if (!$lockedUser->save()) {
                return [false, 'save'];
            }

            // Revoke every old session in the same DB transaction as the password update.
            $lockedUser->tokens()->delete();
            Cache::forget($emailCodeKey);
            return [true, $lockedUser];
        });

        if (!$result[0]) {
            if ($result[1] === 'save') {
                return [false, [500, __('Reset failed')]];
            }
            return [false, [400, __('Incorrect email verification code')]];
        }

        Cache::forget($forgetRequestLimitKey);
        HookManager::call('user.password.reset.after', $result[1]);
        return [true, true];
    }


    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        Cache::put($key, $user->id, 60);

        $redirect = $redirect ?: 'dashboard';
        $loginRedirect = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }
}