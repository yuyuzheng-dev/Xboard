<?php

namespace Plugin\CoinPayments;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Utils\PaymentAmount;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['CoinPayments'] = [
                    'name' => $this->getConfig('display_name', 'CoinPayments'),
                    'icon' => $this->getConfig('icon', '💰'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'type' => 'string',
                'required' => true,
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID'
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'type' => 'string',
                'required' => true,
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值'
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'type' => 'string',
                'required' => true,
                'description' => '填写您的货币代码（大写），建议与 Merchant Settings 中的值相同'
            ]
        ];
    }

    public function pay($order): array
    {
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->getConfig('coinpayments_merchant_id'),
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $this->getConfig('coinpayments_currency'),
            'amountf' => sprintf('%.2f', $order['total_amount'] / 100),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1,
            'data' => 'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params): array|string|bool
    {
        $secret = $this->getConfig('coinpayments_ipn_secret');
        $merchant = $this->getConfig('coinpayments_merchant_id');
        $currency = $this->getConfig('coinpayments_currency');

        if (!is_array($params) || !is_string($secret) || trim($secret) === ''
            || (!is_string($merchant) && !is_int($merchant)) || trim((string) $merchant) === ''
            || !is_string($currency) || trim($currency) === ''
            || (string) ($params['merchant'] ?? '') !== trim((string) $merchant)) {
            return false;
        }

        foreach ($params as $value) {
            if (!is_string($value) && !is_int($value)) {
                return false;
            }
        }
        $signature = request()->header('Hmac', '');
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        ksort($params);
        $canonical = stripslashes(http_build_query($params));
        if (!hash_equals(hash_hmac('sha512', $canonical, trim($secret)), $signature)) {
            return false;
        }

        $status = $params['status'] ?? null;
        if (!is_numeric($status)) {
            return false;
        }
        $status = (int) $status;
        if ($status < 0) {
            return false;
        }
        if ($status < 100 && $status !== 2) {
            return 'IPN OK: pending';
        }

        if (!is_string($params['item_number'] ?? null)
            || trim($params['item_number']) === ''
            || !is_string($params['txn_id'] ?? null)
            || trim($params['txn_id']) === ''
            || strtoupper((string) ($params['currency1'] ?? '')) !== strtoupper(trim($currency))) {
            return false;
        }
        // amount1 is the original merchant currency amount, amount2 is crypto.
        // Never confuse the converted crypto amount with the actual invoice total.
        $paidAmount = PaymentAmount::toCents($params['amount1'] ?? null);
        if ($paidAmount === null || $paidAmount <= 0) {
            return false;
        }

        return [
            'trade_no' => $params['item_number'],
            'callback_no' => $params['txn_id'],
            'paid_amount' => $paidAmount,
            'custom_result' => 'IPN OK',
        ];
    }
}
