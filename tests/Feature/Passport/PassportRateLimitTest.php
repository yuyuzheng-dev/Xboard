<?php

namespace Tests\Feature\Passport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'stop_register' => 0,
            'email_verify' => 0,
            'captcha_enable' => 0,
            'register_limit_by_ip_enable' => 0,
            'password_login_enable' => 1,
        ]);
    }

    public function test_login_is_rate_limited_after_ten_attempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/passport/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);
        }
        $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_email_verification_is_rate_limited_after_three_attempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => 'nobody@example.com']);
        }
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'nobody@example.com',
        ])->assertStatus(429);
    }
}
