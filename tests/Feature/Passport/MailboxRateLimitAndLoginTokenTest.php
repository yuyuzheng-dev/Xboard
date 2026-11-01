<?php

namespace Tests\Feature\Passport;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Auth\EmailVerificationCode;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxRateLimitAndLoginTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        admin_setting([
            'captcha_enable' => 0,
            'email_verify' => 1,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'password_limit_enable' => 1,
            'password_limit_count' => 2,
            'password_limit_expire' => 60,
            'login_with_mail_link_enable' => 1,
        ]);
    }

    private function user(string $email = 'member@example.com'): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('correct-password', PASSWORD_DEFAULT),
            'uuid' => 'mailbox-rate-test-uuid',
            'token' => 'mailbox-rate-test-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function test_case_variants_share_the_same_password_failure_budget(): void
    {
        $user = $this->user();
        $login = app(LoginService::class);

        [$first, $firstError] = $login->login('MEMBER@example.com', 'wrong-password');
        [$second, $secondError] = $login->login('member@EXAMPLE.COM', 'wrong-password');
        [$third, $thirdError] = $login->login('Member@example.com', 'wrong-password');

        $this->assertFalse($first);
        $this->assertFalse($second);
        $this->assertFalse($third);
        $this->assertSame(400, $firstError[0]);
        $this->assertSame(400, $secondError[0]);
        $this->assertSame(429, $thirdError[0]);
        $this->assertSame(2, (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $user->email)));
        $this->assertNull(Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', 'MEMBER@example.com')));
    }

    public function test_password_reset_variants_share_failure_budget(): void
    {
        $this->user();
        $this->assertTrue(EmailVerificationCode::issue('member@example.com', 'reset', '654321'));
        $login = app(LoginService::class);

        foreach (['MEMBER@example.com', 'member@EXAMPLE.com', 'Member@example.com'] as $address) {
            [$success, $error] = $login->resetPassword($address, '000000', 'changed-password');
            $this->assertFalse($success);
            $this->assertSame(400, $error[0]);
        }

        [$success, $error] = $login->resetPassword('member@example.com', '654321', 'changed-password');
        $this->assertFalse($success);
        $this->assertSame(429, $error[0]);
        $this->assertSame(3, (int) Cache::get(CacheKey::get('FORGET_REQUEST_LIMIT', 'member@example.com')));
    }

    public function test_otp_resend_cooldown_cannot_be_bypassed_with_mailbox_case(): void
    {
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'NewUser@example.com',
        ])->assertOk();

        $firstCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'newuser@example.com'));
        $this->assertNotNull($firstCode);

        $this->postJson('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'newUSER@example.com',
        ])->assertStatus(400);

        $this->assertSame($firstCode, Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'newuser@example.com')));
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_mail_link_cooldown_is_shared_and_atomic_for_case_variants(): void
    {
        $this->user();
        $mail = app(MailLinkService::class);

        [$first] = $mail->handleMailLink('MEMBER@example.com');
        [$second, $error] = $mail->handleMailLink('member@EXAMPLE.com');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(429, $error[0]);
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_one_time_mail_login_token_can_only_be_consumed_once(): void
    {
        $user = $this->user();
        Cache::put(CacheKey::get('TEMP_TOKEN', 'test-mail-login-token'), $user->id, 300);
        $service = app(MailLinkService::class);

        $this->assertSame($user->id, $service->handleTokenLogin('test-mail-login-token'));
        $this->assertNull($service->handleTokenLogin('test-mail-login-token'));
    }

    public function test_contended_token_lock_does_not_allow_reuse_or_delete_token(): void
    {
        $user = $this->user();
        $token = 'contended-login-token';
        Cache::put(CacheKey::get('TEMP_TOKEN', $token), $user->id, 300);
        $lock = Cache::lock('mail-login-token:' . hash('sha256', $token), 10);
        $this->assertTrue($lock->get());

        try {
            $this->assertNull(app(MailLinkService::class)->handleTokenLogin($token));
            $this->assertSame($user->id, (int) Cache::get(CacheKey::get('TEMP_TOKEN', $token)));
        } finally {
            $lock->release();
        }

        $this->assertSame($user->id, app(MailLinkService::class)->handleTokenLogin($token));
        $this->assertNull(app(MailLinkService::class)->handleTokenLogin($token));
    }

    public function test_array_verification_token_is_rejected_as_bad_request(): void
    {
        $this->getJson('/api/v1/passport/auth/token2Login?verify%5B%5D=invalid')
            ->assertStatus(400);
    }
}
