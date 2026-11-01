<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\RegisterService;
use App\Services\Auth\EmailVerificationCode;
use App\Exceptions\ApiException;
use App\Models\InviteCode;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RegisterServiceTest extends TestCase
{
    use RefreshDatabase;

    private RegisterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'email_verify' => 1,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'captcha_enable' => 0,
            'register_limit_by_ip_enable' => 0,
        ]);

        $this->service = app(RegisterService::class);
    }

    public function test_validate_register_rejects_missing_cached_email_code(): void
    {
        [$success, $result] = $this->service->validateRegister($this->makeRequest([
            'email_code' => '123456',
        ]));

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    public function test_validate_register_rejects_boolean_email_code(): void
    {
        [$success, $result] = $this->service->validateRegister($this->makeRequest([
            'email_code' => false,
        ]));

        $this->assertFalse($success);
        $this->assertSame(422, $result[0]);
    }

    public function test_validate_register_accepts_matching_cached_email_code(): void
    {
        $this->assertTrue(EmailVerificationCode::issue('user@example.com', 'register', '123456'));

        [$success, $result] = $this->service->validateRegister($this->makeRequest([
            'email_code' => '123456',
        ]));

        $this->assertTrue($success);
        $this->assertNull($result);
    }

    public function test_single_use_invite_cannot_register_multiple_accounts(): void
    {
        admin_setting(['email_verify' => 0, 'invite_force' => 1, 'invite_never_expire' => 0]);

        $inviter = User::create([
            'email' => 'inviter@example.com',
            'password' => 'hashed',
            'uuid' => 'inviter-uuid',
            'token' => 'inviter-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $code = new InviteCode();
        $code->user_id = $inviter->id;
        $code->code = 'INVITE01';
        $code->status = InviteCode::STATUS_UNUSED;
        $code->saveOrFail();

        [$ok, $newUser] = $this->service->register($this->makeRequest([
            'invite_code' => 'INVITE01',
        ]));
        $this->assertTrue($ok);
        $this->assertSame($inviter->id, $newUser->invite_user_id);
        $this->assertSame(InviteCode::STATUS_USED, (int) $code->refresh()->status);

        $this->expectException(ApiException::class);
        $this->service->register($this->makeRequest([
            'email' => 'second@example.com',
            'invite_code' => 'INVITE01',
        ]));
    }

    public function test_orphan_invite_code_cannot_attach_deleted_inviter(): void
    {
        admin_setting(['email_verify' => 0, 'invite_force' => 0]);

        $code = new InviteCode();
        $code->user_id = 999999;
        $code->code = 'ORPHAN01';
        $code->status = InviteCode::STATUS_UNUSED;
        $code->pv = 0;
        $code->created_at = time();
        $code->updated_at = time();
        $code->saveOrFail();

        [$success, $user] = $this->service->register($this->makeRequest([
            'email' => 'orphan-invitee@example.com',
            'invite_code' => 'ORPHAN01',
        ]));

        $this->assertTrue($success);
        $this->assertNull($user->invite_user_id);
        $this->assertFalse(InviteCode::where('code', 'ORPHAN01')->exists());
    }

    public function test_ip_registration_limit_is_atomic_and_failed_validation_releases_slot(): void
    {
        admin_setting([
            'email_verify' => 1,
            'register_limit_by_ip_enable' => 1,
            'register_limit_count' => 1,
            'register_limit_expire' => 60,
            'captcha_enable' => 0,
            'invite_force' => 0,
            'stop_register' => 0,
        ]);

        $request = $this->makeRequest([
            'email' => 'rate-limited@example.com',
            'email_code' => '123456',
        ]);
        $key = CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip());

        // Invalid validation must release its reservation.
        [$invalidSuccess, $invalidError] = $this->service->register($request);
        $this->assertFalse($invalidSuccess);
        $this->assertSame(400, $invalidError[0]);
        $this->assertSame(0, (int) Cache::get($key, 0));

        $this->assertTrue(EmailVerificationCode::issue('rate-limited@example.com', 'register', '123456'));
        [$success] = $this->service->register($request);
        $this->assertTrue($success);
        $this->assertSame(1, (int) Cache::get($key));

        [$blocked, $error] = $this->service->register($this->makeRequest([
            'email' => 'second-rate-limited@example.com',
            'email_code' => '123456',
        ]));
        $this->assertFalse($blocked);
        $this->assertSame(429, $error[0]);
        $this->assertSame(1, (int) Cache::get($key));
    }

    public function test_each_mailbox_has_only_five_failed_registration_code_attempts(): void
    {
        $email = 'victim@example.com';
        $this->assertTrue(EmailVerificationCode::issue($email, 'register', '654321'));

        for ($i = 0; $i < 4; $i++) {
            $request = $this->makeRequest([
                'email' => $email,
                'email_code' => '000000',
            ]);
            $request->server->set('REMOTE_ADDR', '198.51.100.' . ($i + 1));
            [$valid, $error] = $this->service->validateRegister($request);
            $this->assertFalse($valid);
            $this->assertSame(400, $error[0]);
        }

        [$valid] = $this->service->validateRegister($this->makeRequest([
            'email' => $email,
            'email_code' => '654321',
        ]));
        $this->assertTrue($valid, 'Four wrong guesses must not block a valid code.');

        [$valid, $error] = $this->service->validateRegister($this->makeRequest([
            'email' => $email,
            'email_code' => '000000',
        ]));
        $this->assertFalse($valid);
        $this->assertSame(429, $error[0]);
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email)));
        $this->assertSame(5, (int) Cache::get(EmailVerificationCode::registrationFailuresKey($email)));

        [$valid, $error] = $this->service->validateRegister($this->makeRequest([
            'email' => $email,
            'email_code' => '654321',
        ]));
        $this->assertFalse($valid);
        $this->assertSame(429, $error[0]);
        $this->assertFalse(EmailVerificationCode::issue($email, 'register', '111111'));
    }

    public function test_resending_code_cannot_reset_failed_attempt_budget(): void
    {
        $email = 'resend@example.com';
        $this->assertTrue(EmailVerificationCode::issue($email, 'register', '111111'));

        for ($i = 0; $i < 4; $i++) {
            [$valid, $error] = $this->service->validateRegister($this->makeRequest([
                'email' => $email,
                'email_code' => '000000',
            ]));
            $this->assertFalse($valid);
            $this->assertSame(400, $error[0]);
        }

        $this->assertTrue(EmailVerificationCode::issue($email, 'register', '222222'));
        $this->assertSame(4, (int) Cache::get(EmailVerificationCode::registrationFailuresKey($email)));
        [$valid, $error] = $this->service->validateRegister($this->makeRequest([
            'email' => $email,
            'email_code' => '000000',
        ]));
        $this->assertFalse($valid);
        $this->assertSame(429, $error[0]);
        $this->assertFalse(EmailVerificationCode::issue($email, 'register', '333333'));
        Cache::forget(EmailVerificationCode::registrationFailuresKey($email));
        $this->assertTrue(EmailVerificationCode::issue($email, 'register', '333333'));
        [$valid] = $this->service->validateRegister($this->makeRequest([
            'email' => $email,
            'email_code' => '333333',
        ]));
        $this->assertTrue($valid);
    }

    public function test_newly_registered_account_cannot_reuse_email_otp(): void
    {
        $email = 'one-time@example.com';
        $this->assertTrue(EmailVerificationCode::issue($email, 'register', '654321'));
        [$success, $user] = $this->service->register($this->makeRequest([
            'email' => $email,
            'email_code' => '654321',
        ]));

        $this->assertTrue($success);
        $this->assertSame($email, $user->email);
        $this->assertSame('invalid', EmailVerificationCode::verify($email, 'register', '654321'));
        $this->assertNull(Cache::get(EmailVerificationCode::registrationFailuresKey($email)));
    }

    public function test_password_reset_code_cannot_be_guessed_using_register_endpoint(): void
    {
        User::create([
            'email' => 'existing@example.com',
            'password' => 'old-password',
            'uuid' => 'existing-verify-uuid',
            'token' => 'existing-verify-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertTrue(EmailVerificationCode::issue('existing@example.com', 'reset', '123456'));
        foreach (['000000', '123456'] as $code) {
            [$valid, $error] = $this->service->validateRegister($this->makeRequest([
                'email' => 'existing@example.com',
                'email_code' => $code,
            ]));
            $this->assertFalse($valid);
            $this->assertSame(400, $error[0]);
        }
        $this->assertSame('valid', EmailVerificationCode::verify('existing@example.com', 'reset', '123456'));
    }

    private function makeRequest(array $overrides = []): Request
    {
        return Request::create('/api/v1/passport/auth/register', 'POST', array_merge([
            'email' => 'user@example.com',
            'password' => 'password123',
        ], $overrides));
    }
}
