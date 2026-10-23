<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderServiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_only_refunds_once_when_called_with_stale_order_models(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_PENDING,
            'balance_amount' => 100,
            'total_amount' => 1000,
        ]);

        // Load many stale copies before the first cancellation, then replay the same
        // request repeatedly. Only the first pending -> cancelled transition may refund.
        $staleOrders = [];
        for ($i = 0; $i < 50; $i++) {
            $staleOrders[] = Order::findOrFail($order->id);
        }

        foreach ($staleOrders as $index => $staleOrder) {
            $result = (new OrderService($staleOrder))->cancel();
            $this->assertSame($index === 0, $result);
        }
        $this->assertSame(100, User::findOrFail($user->id)->balance);
    }

    public function test_open_only_applies_subscription_once_when_called_with_stale_order_models(): void
    {
        $user = $this->makeUser([
            'balance' => 0,
            'expired_at' => 0,
            'transfer_enable' => 0,
            'u' => 1073741824,
            'd' => 0,
        ]);
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_PROCESSING,
            'balance_amount' => 1100,
            'total_amount' => 0,
        ]);

        $before = time();
        $firstStaleOrder = Order::findOrFail($order->id);
        $secondStaleOrder = Order::findOrFail($order->id);

        (new OrderService($firstStaleOrder))->open();
        (new OrderService($secondStaleOrder))->open();

        $user->refresh();
        $this->assertSame(1, $user->reset_count);
        $this->assertSame(Order::STATUS_COMPLETED, Order::findOrFail($order->id)->status);
        $this->assertLessThan($before + (45 * 86400), $user->expired_at);
    }

    public function test_gift_card_redeem_only_grants_rewards_once_for_stale_code_models(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        $template = GiftCardTemplate::create([
            'name' => 'race-test',
            'description' => 'race-test',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'rewards' => ['balance' => 100],
            'admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        GiftCardCode::create([
            'template_id' => $template->id,
            'code' => 'RACEUNITTEST',
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $firstService = (new GiftCardService('RACEUNITTEST'))->setUser(User::findOrFail($user->id));
        $secondService = (new GiftCardService('RACEUNITTEST'))->setUser(User::findOrFail($user->id));

        $firstService->validate();
        $secondService->validate();
        $firstService->redeem();

        try {
            $secondService->redeem();
            $this->fail('The second stale gift card redeem should fail.');
        } catch (ApiException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $user->refresh();
        $code = GiftCardCode::where('code', 'RACEUNITTEST')->firstOrFail();
        $this->assertSame(100, $user->balance);
        $this->assertSame(1, $code->usage_count);
        $this->assertSame(1, $code->usages()->count());
    }

    public function test_repeated_zero_price_plan_changes_do_not_mint_balance(): void
    {
        admin_setting([
            'surplus_enable' => 1,
            'plan_change_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        $oldPlan = $this->makePlan([
            'name' => 'Paid source plan',
            'prices' => [Plan::PERIOD_MONTHLY => 100],
        ]);
        $user = $this->makeUser([
            'balance' => 0,
            'plan_id' => $oldPlan->id,
            'expired_at' => time() + (30 * 86400),
            'transfer_enable' => $oldPlan->transfer_enable * 1073741824,
            'u' => 0,
            'd' => 0,
        ]);
        $sourceOrder = $this->makeOrder($user, $oldPlan, [
            'status' => Order::STATUS_COMPLETED,
            'total_amount' => 10000,
            'balance_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'created_at' => time(),
        ]);

        $firstBalance = null;
        for ($i = 0; $i < 5; $i++) {
            $freePlan = $this->makePlan([
                'name' => 'Zero price plan ' . $i,
                'prices' => [Plan::PERIOD_MONTHLY => 0],
            ]);
            $order = OrderService::createFromRequest($user->fresh(), $freePlan, Plan::PERIOD_MONTHLY);
            $this->assertSame(0, (int) $order->total_amount);
            $this->assertGreaterThanOrEqual(0, (int) $order->surplus_credit);
            $this->assertLessThanOrEqual(10000, (int) $order->surplus_credit);

            $this->assertTrue((new OrderService($order))->paid($order->trade_no));
            $user->refresh();

            if ($i === 0) {
                $firstBalance = $user->balance;
                $this->assertLessThanOrEqual(10000, $firstBalance);
                $this->assertSame(Order::STATUS_DISCOUNTED, $sourceOrder->refresh()->status);
            } else {
                $this->assertSame($firstBalance, $user->balance);
            }
        }
    }

    public function test_paid_rejects_a_stale_zero_order_after_it_was_cancelled(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_PENDING,
            'total_amount' => 0,
            'balance_amount' => 100,
        ]);
        $staleOrder = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService(Order::findOrFail($order->id)))->cancel());
        $this->assertFalse((new OrderService($staleOrder))->paid($order->trade_no));
        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(100, $user->refresh()->balance);
        $this->assertNull($user->plan_id);
    }

    public function test_zero_checkout_cannot_credit_surplus_more_than_once(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        Sanctum::actingAs($user);
        $order = $this->makeOrder($user, $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'total_amount' => 0,
            'surplus_credit' => 500,
        ]);

        $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
        ])->assertOk()->assertJsonPath('type', -1);

        $this->assertSame(500, $user->refresh()->balance);
        $this->assertSame(Order::STATUS_COMPLETED, $order->refresh()->status);

        $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
        ])->assertStatus(400);

        $this->assertSame(500, $user->refresh()->balance);
    }

    public function test_checkout_rejects_negative_order_amount(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        Sanctum::actingAs($user);

        $order = $this->makeOrder($user, $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'total_amount' => -1,
        ]);

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
        ]);

        $response->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($order->id)->status);
        $this->assertNull(User::findOrFail($user->id)->plan_id);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'race-test@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'token' => '0123456789abcdef0123456789abcdef',
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

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Race Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [
                Plan::PERIOD_MONTHLY => 11,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('race_', true),
            'total_amount' => 0,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
