<?php

namespace Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use Plugin\Epay\Plugin;

class EpayCallbackTest extends TestCase
{
    private function signedParams(array $override = []): array
    {
        $params = array_merge([
            'pid' => '123',
            'out_trade_no' => 'ORDER123',
            'trade_no' => 'GATEWAY456',
            'money' => '12.30',
            'trade_status' => 'TRADE_SUCCESS',
        ], $override);
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'test-secret');
        $params['sign_type'] = 'MD5';
        return $params;
    }

    private function plugin(array $config = []): Plugin
    {
        $plugin = new Plugin('EPay');
        $plugin->setConfig(array_merge(['pid' => '123', 'key' => 'test-secret'], $config));
        return $plugin;
    }

    public function test_verifies_signed_payment_amount_in_integer_cents(): void
    {
        $result = $this->plugin()->notify($this->signedParams());
        $this->assertIsArray($result);
        $this->assertSame(1230, $result['paid_amount']);
        $this->assertSame('ORDER123', $result['trade_no']);
    }

    public function test_rejects_empty_secret_and_wrong_merchant(): void
    {
        $this->assertFalse($this->plugin(['key' => ''])->notify($this->signedParams()));
        $this->assertFalse($this->plugin()->notify($this->signedParams(['pid' => '999'])));
    }

    public function test_rejects_signed_invalid_money_formats(): void
    {
        foreach (['-1', '0', '12.345', '1e3', 'NaN'] as $amount) {
            $this->assertFalse($this->plugin()->notify($this->signedParams(['money' => $amount])));
        }
    }

    public function test_rejects_successful_signature_for_non_success_status(): void
    {
        $this->assertFalse($this->plugin()->notify($this->signedParams(['trade_status' => 'TRADE_CLOSED'])));
    }
}
