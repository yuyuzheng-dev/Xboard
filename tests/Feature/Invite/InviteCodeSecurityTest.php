<?php

namespace Tests\Feature\Invite;

use App\Models\InviteCode;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteCodeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_random_invite_codes_use_expected_alphabet_and_are_unique_in_batch(): void
    {
        $codes = [];
        for ($i = 0; $i < 500; $i++) {
            $code = Helper::randomChar(8);
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{8}\z/', $code);
            $codes[] = $code;
        }

        $this->assertCount(500, array_unique($codes));
    }

    public function test_invite_generation_respects_limit_and_codes_are_unique(): void
    {
        admin_setting(['invite_gen_limit' => 5]);
        $user = $this->makeUser(['last_login_at' => time()]);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/user/invite/save')->assertOk();
        }

        $codes = InviteCode::where('user_id', $user->id)->pluck('code')->all();
        $this->assertCount(5, $codes);
        $this->assertCount(5, array_unique($codes));

        $this->getJson('/api/v1/user/invite/save')->assertStatus(400);
        $this->assertSame(5, InviteCode::where('user_id', $user->id)->count());
    }

    public function test_unique_invite_migration_deduplicates_and_preserves_used_state(): void
    {
        Schema::table('v2_invite_code', function ($table) {
            $table->dropUnique('uk_invite_code');
        });

        $firstOwner = $this->makeUser();
        $secondOwner = $this->makeUser();
        DB::table('v2_invite_code')->insert([
            [
                'user_id' => $firstOwner->id,
                'code' => 'DUPLICATE',
                'status' => 0,
                'pv' => 2,
                'created_at' => time(),
                'updated_at' => time(),
            ],
            [
                'user_id' => $secondOwner->id,
                'code' => 'DUPLICATE',
                'status' => 1,
                'pv' => 3,
                'created_at' => time(),
                'updated_at' => time(),
            ],
        ]);

        $migration = require database_path('migrations/2026_10_23_000001_unique_invite_code.php');
        $migration->up();

        $rows = DB::table('v2_invite_code')->where('code', 'DUPLICATE')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($firstOwner->id, (int) $rows[0]->user_id);
        $this->assertSame(1, (int) $rows[0]->status);
        $this->assertSame(5, (int) $rows[0]->pv);
        $this->assertTrue(Schema::hasIndex('v2_invite_code', 'uk_invite_code', 'unique'));
    }

    public function test_clear_user_command_removes_owned_invite_codes(): void
    {
        $user = $this->makeUser([
            'last_login_at' => null,
            'plan_id' => null,
            'transfer_enable' => 0,
            'expired_at' => 0,
        ]);
        $code = new InviteCode();
        $code->user_id = $user->id;
        $code->code = 'ORPHAN123';
        $code->status = InviteCode::STATUS_UNUSED;
        $code->pv = 0;
        $code->created_at = time();
        $code->updated_at = time();
        $code->saveOrFail();

        Artisan::call('clear:user');

        $this->assertFalse(User::whereKey($user->id)->exists());
        $this->assertFalse(InviteCode::where('code', 'ORPHAN123')->exists());
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => uniqid('invite_', true) . '@example.com',
            'password' => 'password',
            'uuid' => uniqid('invite_uuid_'),
            'token' => uniqid('invite_token_'),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
