<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommissionTransferIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'email' => 'transfer@example.com',
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => 'commission-transfer-uuid',
            'token' => 'commission-transfer-token',
            'balance' => 0,
            'commission_balance' => 200,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function test_repeated_transfers_never_spend_more_than_available_commission(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/v1/user/transfer', ['transfer_amount' => 100]);
            if ($i < 2) {
                $response->assertOk()->assertJsonPath('status', 'success');
            } else {
                $response->assertStatus(400)->assertJsonPath('status', 'fail');
            }
        }

        $user->refresh();
        $this->assertSame(0, (int) $user->commission_balance);
        $this->assertSame(200, (int) $user->balance);
    }

    public function test_nonpositive_and_noninteger_transfers_are_rejected(): void
    {
        Sanctum::actingAs($this->user());
        foreach ([0, -1, '1.25', 'not-an-integer'] as $amount) {
            $this->postJson('/api/v1/user/transfer', ['transfer_amount' => $amount])
                ->assertUnprocessable();
        }
    }
}
