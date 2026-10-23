<?php

namespace Tests\Feature\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugin\Coinbase\Plugin as CoinbasePlugin;
use Plugin\Btcpay\Plugin as BTCPayPlugin;
use Plugin\AlipayF2f\Plugin as AlipayPlugin;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/plugins-core/Coinbase/Plugin.php';
require_once dirname(__DIR__, 3) . '/plugins-core/Btcpay/Plugin.php';
require_once dirname(__DIR__, 3) . '/plugins-core/AlipayF2f/Plugin.php';

class SettledWebhooksTest extends TestCase
{
    private function fakeWebhook(string $body, string $header, string $signature): void
    {
        $request = Request::create('/api/v1/guest/payment/notify', 'POST', [], [], [], [
            'HTTP_' . strtoupper(str_replace('-', '_', $header)) => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        $this->app->instance('request', $request);
    }

    private function coinbase(string $type, string $amount = '12.30'): array|bool
    {
        $payload = json_encode([
            'event' => [
                'type' => $type,
                'data' => [
                    'id' => 'charge-id',
                    'metadata' => ['outTradeNo' => 'ORDER-1'],
                    'pricing' => ['local' => ['amount' => $amount, 'currency' => 'CNY']],
                ],
            ],
        ]);
        $this->fakeWebhook($payload, 'X-Cc-Webhook-Signature', hash_hmac('sha256', $payload, 'shared-secret'));
        $plugin = new CoinbasePlugin('Coinbase');
        $plugin->setConfig(['coinbase_webhook_key' => 'shared-secret']);
        return $plugin->notify([]);
    }

    public function test_coinbase_does_not_fulfill_pending_created_or_failed_charges(): void
    {
        foreach (['charge:created', 'charge:pending', 'charge:failed'] as $type) {
            $this->assertFalse($this->coinbase($type));
        }
    }

    public function test_coinbase_confirmed_charge_returns_signed_amount(): void
    {
        $result = $this->coinbase('charge:confirmed');
        $this->assertIsArray($result);
        $this->assertSame('ORDER-1', $result['trade_no']);
        $this->assertSame('charge-id', $result['callback_no']);
        $this->assertSame(1230, $result['paid_amount']);
        $this->assertFalse($this->coinbase('charge:confirmed', '0'));
    }

    private function btcpay(string $eventType, string $status): array|bool
    {
        Http::fake([
            'https://btcpay.test/api/v1/stores/store-1/invoices/invoice-1' => Http::response([
                'id' => 'invoice-1',
                'status' => $status,
                'additionalStatus' => 'None',
                'currency' => 'CNY',
                'amount' => '12.30',
                'metadata' => ['orderId' => 'ORDER-1'],
            ]),
        ]);

        $body = json_encode(['type' => $eventType, 'invoiceId' => 'invoice-1']);
        $this->fakeWebhook($body, 'Btcpay-Sig', 'sha256=' . hash_hmac('sha256', $body, 'shared-secret'));

        $plugin = new BTCPayPlugin('Btcpay');
        $plugin->setConfig([
            'btcpay_webhook_key' => 'shared-secret',
            'btcpay_url' => 'https://btcpay.test/',
            'btcpay_storeId' => 'store-1',
            'btcpay_api_key' => 'api-key',
        ]);
        return $plugin->notify([]);
    }

    public function test_btcpay_requires_settled_event_and_settled_invoice(): void
    {
        $this->assertFalse($this->btcpay('InvoiceCreated', 'Settled'));
        $this->assertFalse($this->btcpay('InvoiceSettled', 'Processing'));
        $result = $this->btcpay('InvoiceSettled', 'Settled');
        $this->assertIsArray($result);
        $this->assertSame(1230, $result['paid_amount']);
    }

    public function test_alipay_rejects_wrong_application_and_missing_amount(): void
    {
        $plugin = new AlipayPlugin('AlipayF2f');
        $plugin->setConfig(['app_id' => 'expected-app']);
        $this->assertFalse($plugin->notify([
            'app_id' => 'wrong-app', 'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'GATEWAY-1', 'out_trade_no' => 'ORDER-1',
            'total_amount' => '12.30',
        ]));
        $this->assertFalse($plugin->notify([
            'app_id' => 'expected-app', 'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'GATEWAY-1', 'out_trade_no' => 'ORDER-1',
        ]));
    }
}
