<?php

namespace Tests\Feature\Passport;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Auth\EmailVerificationCode;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegisterService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegistrationEmailOtpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        admin_setting([
            'email_verify' => 1,
            'captcha_enable' => 0,
            'stop_register' => 0,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'register_limit_by_ip_enable' => 0,
        ]);
    }

    public function test_new_mailbox_receives_registration_only_code(): void
    {
        $email = 'new-flow@example.com';
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => $email])->assertOk();

        $code = (string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertSame('valid', EmailVerificationCode::verify($email, 'register', $code));
        $this->assertSame('invalid', EmailVerificationCode::verify($email, 'reset', $code));
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_existing_mailbox_receives_reset_only_code(): void
    {
        $email = 'existing-flow@example.com';
        User::create([
            'email' => $email,
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => 'existing-flow-uuid',
            'token' => 'existing-flow-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => $email])->assertOk();
        $code = (string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        $this->assertSame('valid', EmailVerificationCode::verify($email, 'reset', $code));
        $this->assertSame('invalid', EmailVerificationCode::verify($email, 'register', $code));

        [$accepted] = app(RegisterService::class)->validateRegister(Request::create(
            '/api/v1/passport/auth/register', 'POST', [
                'email' => $email,
                'password' => 'another-password',
                'email_code' => $code,
            ]
        ));
        $this->assertFalse($accepted);
    }

    public function test_registration_lockout_prevents_sending_a_fresh_code(): void
    {
        $email = 'locked-flow@example.com';
        $this->assertTrue(EmailVerificationCode::issue($email, 'register', '654321'));
        for ($i = 0; $i < 5; $i++) {
            EmailVerificationCode::verify($email, 'register', '000000');
        }

        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => $email])
            ->assertStatus(429);
        Queue::assertNotPushed(SendEmailJob::class);
    }

    public function test_malformed_code_has_same_validation_error_for_existing_and_new_mailbox(): void
    {
        $existing = 'enumeration-flow@example.com';
        User::create([
            'email' => $existing,
            'password' => 'password',
            'uuid' => 'enumeration-flow-uuid',
            'token' => 'enumeration-flow-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        foreach ([$existing, 'other-flow@example.com'] as $email) {
            [$accepted, $error] = app(RegisterService::class)->validateRegister(Request::create(
                '/api/v1/passport/auth/register', 'POST', [
                    'email' => $email,
                    'password' => 'another-password',
                    'email_code' => false,
                ]
            ));
            $this->assertFalse($accepted);
            $this->assertSame(422, $error[0]);
        }
    }
}
