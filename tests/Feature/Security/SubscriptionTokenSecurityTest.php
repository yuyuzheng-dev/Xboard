<?php
namespace Tests\Feature\Security;

use App\Http\Middleware\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SubscriptionTokenSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'email' => 'subscription-credential@example.com', 'password' => 'password',
            'uuid' => 'subscription-credential-uuid', 'token' => '2' . str_repeat('a', 31),
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function attempt(mixed $token): int
    {
        $request = Request::create('/api/v1/client/subscribe', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $token]));
        try {
            $response = (new Client())->handle($request, static fn($request) => response('ok'));
            return $response->getStatusCode();
        } catch (\App\Exceptions\ApiException $exception) {
            return 403;
        }
    }

    public function test_non_string_credentials_are_never_accepted(): void
    {
        $this->user();
        foreach ([2, 2.0, true, false, null, ['token' => 2], []] as $value) {
            $this->assertSame(403, $this->attempt($value));
        }
    }

    public function test_collation_aliases_are_not_bearer_credentials(): void
    {
        $user = $this->user();
        foreach (['2', strtoupper($user->token), ' ' . $user->token, str_repeat('a', 513)] as $value) {
            $this->assertSame(403, $this->attempt($value));
        }
    }

    public function test_exact_string_token_still_authenticates(): void
    {
        $user = $this->user();
        $this->assertSame(200, $this->attempt($user->token));
    }
}
