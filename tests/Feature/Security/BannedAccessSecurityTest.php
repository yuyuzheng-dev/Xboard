<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\Admin;
use App\Http\Middleware\Client;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class BannedAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $isAdmin = false): User
    {
        return User::query()->create([
            'email' => 'banned-access-' . Helper::guid(true) . '@example.com',
            'password' => password_hash('correct-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'banned' => false,
            'is_admin' => $isAdmin,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function test_existing_sanctum_session_stops_working_after_user_is_banned(): void
    {
        $user = $this->makeUser();
        $accessToken = $user->createToken('session-before-ban')->plainTextToken;
        $user->banned = true;
        $user->saveOrFail();

        $this->getJson('/api/v1/user/checkLogin', [
            'Authorization' => 'Bearer ' . $accessToken,
        ])->assertStatus(403);
    }

    public function test_unbanned_user_can_still_use_existing_sanctum_session(): void
    {
        $user = $this->makeUser();
        $accessToken = $user->createToken('active-session')->plainTextToken;

        $this->getJson('/api/v1/user/checkLogin', [
            'Authorization' => 'Bearer ' . $accessToken,
        ])->assertOk();
    }

    public function test_existing_subscription_token_is_rejected_for_banned_user(): void
    {
        $user = $this->makeUser();
        $user->banned = true;
        $user->saveOrFail();

        $this->getJson('/api/v1/client/subscribe?token=' . $user->token)
            ->assertStatus(403);
    }

    public function test_unbanned_user_keeps_subscription_token_access(): void
    {
        $user = $this->makeUser();
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => $user->token]);
        $response = (new Client())->handle($request, static fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_banned_admin_is_rejected_by_admin_middleware(): void
    {
        $user = $this->makeUser(true);
        $user->banned = true;
        $user->saveOrFail();

        $guard = Mockery::mock();
        $guard->shouldReceive('user')->once()->andReturn($user);
        Auth::shouldReceive('guard')->once()->with('sanctum')->andReturn($guard);

        $response = (new Admin())->handle(
            Request::create('/api/v2/admin', 'GET'),
            static fn () => response('ok')
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
