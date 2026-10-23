<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Utils\PaymentAmount;
use Illuminate\Support\Facades\Http;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
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
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $signature = request()->header('Btcpay-Sig', '');
        $secret = $this->getConfig('btcpay_webhook_key');
        if (!is_string($secret) || trim($secret) === ''
            || !is_string($signature)
            || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $signature)) {
            return false;
        }

        $event = json_decode($payload, true);
        // Do not fulfill on InvoiceCreated/Processing/Expired/etc.
        if (!is_array($event) || ($event['type'] ?? null) !== 'InvoiceSettled') {
            return false;
        }
        $invoiceId = $event['invoiceId'] ?? null;
        if (!is_string($invoiceId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $invoiceId)) {
            return false;
        }

        $baseUrl = $this->getConfig('btcpay_url');
        $storeId = $this->getConfig('btcpay_storeId');
        $apiKey = $this->getConfig('btcpay_api_key');
        if (!is_string($baseUrl) || !preg_match('/\Ahttps:\/\//i', $baseUrl)
            || !is_string($storeId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $storeId)
            || !is_string($apiKey) || trim($apiKey) === '') {
            return false;
        }

        $url = rtrim($baseUrl, '/') . '/api/v1/stores/' . $storeId . '/invoices/' . $invoiceId;
        $response = Http::withHeaders(['Authorization' => 'token ' . $apiKey])
            ->timeout(10)->get($url);
        if (!$response->successful()) {
            return false;
        }
        $invoice = $response->json();
        if (!is_array($invoice) || ($invoice['id'] ?? null) !== $invoiceId
            || ($invoice['status'] ?? null) !== 'Settled'
            || !in_array($invoice['additionalStatus'] ?? 'None', ['None', 'PaidOver'], true)
            || ($invoice['currency'] ?? null) !== 'CNY'
            || !is_string($invoice['metadata']['orderId'] ?? null)
            || trim($invoice['metadata']['orderId']) === '') {
            return false;
        }

        $paidAmount = PaymentAmount::toCents($invoice['amount'] ?? null);
        if ($paidAmount === null || $paidAmount <= 0) {
            return false;
        }
        return [
            'trade_no' => $invoice['metadata']['orderId'],
            'callback_no' => $invoiceId,
            'paid_amount' => $paidAmount,
        ];
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 