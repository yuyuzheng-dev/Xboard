<?php
namespace Tests\Feature\Security;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class TelegramAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function ordinaryUser(): User
    {
        return User::create([
            'email' => 'telegram-ordinary@example.com', 'password' => 'password',
            'uuid' => 'telegram-ordinary-uuid', 'token' => 'telegram-ordinary-token',
            'telegram_id' => 12345, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function controller(): TelegramController
    {
        return new TelegramController(Mockery::mock(TelegramService::class), Mockery::mock(UserService::class));
    }

    private function request(array $json, string $token): Request
    {
        return Request::create('/api/v1/guest/telegram/webhook?access_token=' . urlencode($token),
            'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($json));
    }

    public function test_unconfigured_webhook_secret_is_not_a_valid_credential(): void
    {
        admin_setting(['telegram_bot_token' => '']);
        $this->expectException(ApiException::class);
        $this->controller()->webhook($this->request([], md5('')));
    }

    public function test_webhook_clears_state_on_reused_controller_instance(): void
    {
        admin_setting(['telegram_bot_token' => '123456:secret']);
        $controller = $this->controller();
        $property = new \ReflectionProperty(TelegramController::class, 'msg');
        $property->setValue($controller, (object) ['command' => '/start', 'chat_id' => 1]);
        $controller->webhook($this->request(['edited_message' => ['text' => 'not a new message']], md5('123456:secret')));
        $this->assertNull($property->getValue($controller));
    }

    public function test_ordinary_user_cannot_invoke_admin_ticket_reply_service(): void
    {
        $user = $this->ordinaryUser();
        $this->expectException(ApiException::class);
        (new TicketService())->replyByAdmin(999999, 'test', $user->id);
    }

    public function test_banned_administrator_cannot_invoke_admin_ticket_reply_service(): void
    {
        $user = $this->ordinaryUser();
        $user->is_admin = 1;
        $user->banned = 1;
        $user->save();
        $this->expectException(ApiException::class);
        (new TicketService())->replyByAdmin(999999, 'test', $user->id);
    }
}
