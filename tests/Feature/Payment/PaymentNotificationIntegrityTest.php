<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentNotificationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function payment(): Payment
    {
        return Payment::create([
            'name' => 'test EPay',
            'payment' => 'EPay',
            'uuid' => 'callback-test-uuid',
            'enable' => true,
            'config' => ['key' => 'dummy', 'pid' => '123'],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function order(Payment $payment, array $overrides = []): Order
    {
        $user = User::create([
            'email' => uniqid('payment_', true) . '@example.com',
            'password' => 'password',
            'uuid' => uniqid('user_'),
            'token' => uniqid('token_'),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = Plan::create([
            'name' => 'Payment integrity plan',
            'transfer_enable' => 10,
            'group_id' => null,
            'show' => 1,
            'renew' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'trade_no' => uniqid('order_', true),
            'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
            'payment_id' => $payment->id,
            'total_amount' => 1000,
            'handling_amount' => 25,
            'balance_amount' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function verify(Order $order, Payment $payment, array $overrides = []): bool
    {
        $service = Mockery::mock(PaymentService::class);
        $service->method = 'EPay';
        $service->shouldReceive('getPaymentId')->andReturn($payment->id);
        $method = new \ReflectionMethod(PaymentController::class, 'handle');

        return $method->invoke(new PaymentController(), array_merge([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-receipt-001',
            'paid_amount' => 1025,
        ], $overrides), $service);
    }

    public function test_mismatched_payment_config_does_not_open_order(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment, ['payment_id' => null]);

        $this->assertFalse($this->verify($order, $payment));
        $this->assertSame(Order::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_signed_but_underpaid_callback_is_rejected(): void
    {
        $payment = $this->payment();
        $order = $this->order($payment);

        $this->assertFalse($this->verify($order, $payment, ['paid_amount' => 1000]));
        $this->assertSame(Order::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_reused_gateway_receipt_is_rejected(): void
    {
        $payment = $this->payment();
        $this->order($payment, [
            'status' => Order::STATUS_COMPLETED,
            'callback_no' => 'gateway-receipt-001',
        ]);
        $target = $this->order($payment);

        $this->assertFalse($this->verify($target, $payment));
        $this->assertSame(Order::STATUS_PENDING, $target->refresh()->status);
    }
}
