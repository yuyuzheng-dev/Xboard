<?php

namespace Tests\Feature\Payment;

use Plugin\Mgate\Plugin as MGatePlugin;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/plugins-core/Mgate/Plugin.php';

class MgateCallbackTest extends TestCase
{
    private function signedCallback(array $params): array
    {
        ksort($params);
        $params['sign'] = md5(http_build_query($params) . 'shared-secret');
        return $params;
    }

    private function plugin(): MGatePlugin
    {
        $plugin = new MGatePlugin('MGate');
        $plugin->setConfig([
            'mgate_app_id' => 'app-1',
            'mgate_app_secret' => 'shared-secret',
            'mgate_source_currency' => 'CNY',
        ]);
        return $plugin;
    }

    public function test_mgate_requires_success_status_and_returns_signed_amount(): void
    {
        $params = $this->signedCallback([
            'app_id' => 'app-1',
            'status' => 'paid',
            'total_amount' => '1025',
            'source_currency' => 'CNY',
            'out_trade_no' => 'ORDER-1',
            'trade_no' => 'MGATE-1',
        ]);

        $result = $this->plugin()->notify($params);
        $this->assertIsArray($result);
        $this->assertSame('ORDER-1', $result['trade_no']);
        $this->assertSame('MGATE-1', $result['callback_no']);
        $this->assertSame(1025, $result['paid_amount']);
    }

    public function test_mgate_rejects_pending_or_failed_status(): void
    {
        foreach (['pending', 'failed', 'unpaid'] as $status) {
            $params = $this->signedCallback([
                'app_id' => 'app-1',
                'status' => $status,
                'total_amount' => '1025',
                'source_currency' => 'CNY',
                'out_trade_no' => 'ORDER-1',
                'trade_no' => 'MGATE-1',
            ]);
            $this->assertFalse($this->plugin()->notify($params));
        }
    }

    public function test_mgate_rejects_wrong_app_currency_and_missing_amount(): void
    {
        $base = [
            'app_id' => 'app-1',
            'status' => 'success',
            'total_amount' => '1025',
            'source_currency' => 'CNY',
            'out_trade_no' => 'ORDER-1',
            'trade_no' => 'MGATE-1',
        ];

        $wrongApp = $base;
        $wrongApp['app_id'] = 'other-app';
        $this->assertFalse($this->plugin()->notify($this->signedCallback($wrongApp)));

        $wrongCurrency = $base;
        $wrongCurrency['source_currency'] = 'USD';
        $this->assertFalse($this->plugin()->notify($this->signedCallback($wrongCurrency)));

        unset($base['total_amount']);
        $this->assertFalse($this->plugin()->notify($this->signedCallback($base)));
    }

    public function test_mgate_rejects_tampered_amount_after_signing(): void
    {
        $params = $this->signedCallback([
            'app_id' => 'app-1',
            'status' => 'TRADE_SUCCESS',
            'total_amount' => '1025',
            'source_currency' => 'CNY',
            'out_trade_no' => 'ORDER-1',
            'trade_no' => 'MGATE-1',
        ]);
        $params['total_amount'] = '1';
        $this->assertFalse($this->plugin()->notify($params));
    }
}
