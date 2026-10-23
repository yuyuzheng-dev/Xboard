<?php

namespace Tests\Feature\Passport;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PasswordResetRevokesTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_revokes_all_existing_access_tokens(): void
    {
        $user = User::create([
            'email' => 'reset@example.com',
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => 'reset-test-uuid',
            'token' => 'reset-test-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $token = $user->createToken('old-device')->plainTextToken;
        $this->assertNotNull(AuthService::findUserByBearerToken($token));
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'reset@example.com'), '123456', 300);

        [$ok] = (new LoginService())->resetPassword('reset@example.com', '123456', 'new-secure-password');

        $this->assertTrue($ok);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull(AuthService::findUserByBearerToken($token));
    }
}
