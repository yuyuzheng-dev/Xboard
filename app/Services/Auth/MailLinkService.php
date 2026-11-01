<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\EmailIdentity;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理邮件链接登录逻辑
     *
     * @param string $email 用户邮箱
     * @param string|null $redirect 重定向地址
     * @return array 返回处理结果
     */
    public function handleMailLink(string $email, ?string $redirect = null): array
    {
        $email = EmailIdentity::normalize($email);
        if (!(int) admin_setting('login_with_mail_link_enable')) {
            return [false, [404, null]];
        }

        $user = User::byEmail($email)->first();
        if (!$user || !$user->matchesEmailIdentity($email)) {
            return [true, true]; // Do not mail a collated-but-different user's login link.
        }

        // Cache::add reserves the cooldown atomically across concurrent requests.
        // Its key must match all case variants of the same account.
        $cooldownKey = CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email);
        if (!Cache::add($cooldownKey, time(), 60)) {
            return [false, [429, __('Sending frequently, please try again later')]];
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);

        $redirectUrl = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect ?: 'dashboard');
        if (admin_setting('app_url')) {
            $link = admin_setting('app_url') . $redirectUrl;
        } else {
            $link = url($redirectUrl);
        }

        $this->sendMailLinkEmail($user, $link);

        return [true, true];
    }

    /**
     * 发送邮件链接登录邮件
     *
     * @param User $user 用户对象
     * @param string $link 登录链接
     * @return void
     */
    private function sendMailLinkEmail(User $user, string $link): void
    {
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'mailLogin',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'link' => $link,
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    /**
     * 处理Token登录
     * 
     * @param string $token 登录令牌
     * @return int|null 用户ID或null
     */
    public function handleTokenLogin(string $token): ?int
    {
        if ($token === '' || strlen($token) > 512) {
            return null;
        }

        $key = CacheKey::get('TEMP_TOKEN', $token);
        // Cache::pull() is get+forget in some stores, not an atomic one-time consume.
        // Lock on the token across workers before reading and deleting its cache entry.
        try {
            return Cache::lock('mail-login-token:' . hash('sha256', $token), 10)
                ->block(2, function () use ($key): ?int {
                    $userId = Cache::get($key);
                    if (!is_numeric($userId) || (int) $userId < 1) {
                        return null;
                    }

                    // Consume even for an account that has since been banned or removed.
                    Cache::forget($key);
                    $user = User::find((int) $userId);

                    return ($user && !$user->banned) ? (int) $user->id : null;
                });
        } catch (LockTimeoutException $exception) {
            return null;
        }
    }
}