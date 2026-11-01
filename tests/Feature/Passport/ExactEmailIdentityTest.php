<?php

namespace Tests\Feature\Passport;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExactEmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        admin_setting([
            'login_with_mail_link_enable' => 1,
            'password_limit_enable' => 0,
            'captcha_enable' => 0,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => 'exact-identity-uuid',
            'token' => 'exact-identity-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function test_accented_and_unaccented_mailboxes_are_distinct_identities(): void
    {
        $user = $this->user('josé@example.com');

        $this->assertTrue($user->matchesEmailIdentity('JOSÉ@example.com') === false);
        $this->assertTrue($user->matchesEmailIdentity('josé@example.com'));
        $this->assertFalse($user->matchesEmailIdentity('jose@example.com'));

        // MySQL accent-insensitive collations may resolve this to the victim row.
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'jose@example.com'), '123456', 300);
        [$success] = app(LoginService::class)->resetPassword('jose@example.com', '123456', 'attacker-password');
        $this->assertFalse($success);
        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
    }

    public function test_mail_link_cannot_be_issued_to_a_collated_but_different_mailbox(): void
    {
        $this->user('josé@example.com');
        Queue::fake();

        [$success] = app(MailLinkService::class)->handleMailLink('jose@example.com');

        $this->assertTrue($success);
        Queue::assertNotPushed(SendEmailJob::class);
    }

    public function test_normalized_case_variants_still_reset_the_correct_user(): void
    {
        $user = $this->user('owner@example.com');
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'OWNER@example.com'), '654321', 300);

        [$success] = app(LoginService::class)->resetPassword('OWNER@example.com', '654321', 'changed-password');

        $this->assertTrue($success);
        $this->assertTrue(password_verify('changed-password', $user->fresh()->password));
    }

    public function test_login_does_not_authenticate_collated_mailbox_alias(): void
    {
        $this->user('josé@example.com');
        [$success] = app(LoginService::class)->login('jose@example.com', 'old-password');
        $this->assertFalse($success);
    }

    public function test_email_validation_rejects_oversized_address(): void
    {
        $response = $this->postJson('/api/v1/passport/auth/forget', [
            'email' => str_repeat('a', 65) . '@example.com',
            'password' => 'new-password',
            'email_code' => '123456',
        ]);
        $response->assertUnprocessable();
    }
}
