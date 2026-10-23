<?php

namespace Tests\Feature\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugin\Coinbase\Plugin as CoinbasePlugin;
use Plugin\Btcpay\Plugin as BTCPayPlugin;
use Plugin\AlipayF2f\Plugin as AlipayPlugin;
use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/plugins-core/Coinbase/Plugin.php';
require_once dirname(__DIR__, 3) . '/plugins-core/Btcpay/Plugin.php';
require_once dirname(__DIR__, 3) . '/plugins-core/AlipayF2f/Plugin.php';
require_once dirname(__DIR__, 3) . '/plugins-core/CoinPayments/Plugin.php';

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

    public function test_btcpay_rejects_nonsettled_events(): void
    {
        $this->assertFalse($this->btcpay('InvoiceCreated', 'Settled'));
    }

    public function test_btcpay_rejects_nonsettled_invoices(): void
    {
        $this->assertFalse($this->btcpay('InvoiceSettled', 'Processing'));
    }

    public function test_btcpay_accepts_settled_invoice_with_verified_amount(): void
    {
        $result = $this->btcpay('InvoiceSettled', 'Settled');
        $this->assertIsArray($result);
        $this->assertSame(1230, $result['paid_amount']);
    }

    private function coinpayments(string $amount, string $currency = 'CNY'): array|string|bool
    {
        $params = [
            'merchant' => 'merchant-1',
            'status' => '100',
            'item_number' => 'ORDER-1',
            'txn_id' => 'CRYPTO-1',
            'amount1' => $amount,
            'currency1' => $currency,
        ];
        ksort($params);
        $signature = hash_hmac('sha512', stripslashes(http_build_query($params)), 'shared-secret');
        $this->fakeWebhook('coinpayments', 'Hmac', $signature);

        $plugin = new CoinPaymentsPlugin('CoinPayments');
        $plugin->setConfig([
            'coinpayments_merchant_id' => 'merchant-1',
            'coinpayments_currency' => 'CNY',
            'coinpayments_ipn_secret' => 'shared-secret',
        ]);
        return $plugin->notify($params);
    }

    public function test_coinpayments_validates_merchant_currency_and_signed_amount(): void
    {
        $result = $this->coinpayments('12.30');
        $this->assertIsArray($result);
        $this->assertSame(1230, $result['paid_amount']);
        $this->assertSame('ORDER-1', $result['trade_no']);
    }

    public function test_coinpayments_rejects_wrong_currency(): void
    {
        $this->assertFalse($this->coinpayments('12.30', 'USD'));
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
