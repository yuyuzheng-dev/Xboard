<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\RegisterService;
use App\Models\InviteCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\InviteCode;
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
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'user@example.com'), 123456, 300);

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

    private function makeRequest(array $overrides = []): Request
    {
        return Request::create('/api/v1/passport/auth/register', 'POST', array_merge([
            'email' => 'user@example.com',
            'password' => 'password123',
        ], $overrides));
    }
}
