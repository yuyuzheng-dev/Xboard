<?php

namespace Plugin\Mgate;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use Curl\Curl;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['MGate'] = [
                    'name' => $this->getConfig('display_name', 'MGate'),
                    'icon' => $this->getConfig('icon', '🏛️'),
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
            'mgate_url' => [
                'label' => 'API地址',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate支付网关API地址'
            ],
            'mgate_app_id' => [
                'label' => 'APP ID',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate应用标识符'
            ],
            'mgate_app_secret' => [
                'label' => 'App Secret',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate应用密钥'
            ],
            'mgate_source_currency' => [
                'label' => '源货币',
                'type' => 'string',
                'description' => '默认CNY，源货币类型'
            ]
        ];
    }

    public function pay($order): array
    {
        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url']
        ];

        if ($this->getConfig('mgate_source_currency')) {
            $params['source_currency'] = $this->getConfig('mgate_source_currency');
        }

        $params['app_id'] = $this->getConfig('mgate_app_id');
        ksort($params);
        $str = http_build_query($params) . $this->getConfig('mgate_app_secret');
        $params['sign'] = md5($str);

        $gatewayUrl = $this->getConfig('mgate_url');
        if (!is_string($gatewayUrl) || !preg_match('/\Ahttps:\/\//i', $gatewayUrl)) {
            throw new ApiException('MGate gateway must use HTTPS');
        }

        $curl = new Curl();
        $curl->setUserAgent('MGate');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 1);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->post($this->getConfig('mgate_url') . '/v1/gateway/fetch', http_build_query($params));
        $result = $curl->response;

        if (!$result) {
            throw new ApiException('网络异常');
        }

        if ($curl->error) {
            if (isset($result->errors)) {
                $errors = (array) $result->errors;
                throw new ApiException($errors[array_keys($errors)[0]][0]);
            }
            if (isset($result->message)) {
                throw new ApiException($result->message);
            }
            throw new ApiException('未知错误');
        }

        $curl->close();

        if (!isset($result->data->trade_no)) {
            throw new ApiException('接口请求失败');
        }

        return [
            'type' => 1,
            'data' => $result->data->pay_url
        ];
    }

    public function notify($params): array|bool
    {
        $secret = $this->getConfig('mgate_app_secret');
        $appId = $this->getConfig('mgate_app_id');
        if (!is_string($secret) || trim($secret) === ''
            || (!is_string($appId) && !is_int($appId)) || trim((string) $appId) === ''
            || !is_array($params)) {
            return false;
        }
        foreach ($params as $value) {
            if (!is_string($value) && !is_int($value)) {
                return false;
            }
        }

        $status = strtolower(trim((string) ($params['status'] ?? '')));
        if (!in_array($status, ['paid', 'success', 'trade_success', '2'], true)
            || (string) ($params['app_id'] ?? '') !== (string) $appId
            || !is_string($params['sign'] ?? null)
            || !is_string($params['out_trade_no'] ?? null) || trim($params['out_trade_no']) === ''
            || !is_string($params['trade_no'] ?? null) || trim($params['trade_no']) === '') {
            return false;
        }

        // MGate's total_amount is integer cents, not decimal yuan. It must be signed and
        // returned to the common callback handler for exact order+fee comparison.
        $amount = $params['total_amount'] ?? null;
        if ((!is_string($amount) && !is_int($amount))
            || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $amount)) {
            return false;
        }
        $paidAmount = (int) $amount;
        if ($paidAmount > 2147483647) {
            return false;
        }

        $expectedCurrency = strtoupper(trim((string) ($this->getConfig('mgate_source_currency') ?: 'CNY')));
        $currency = strtoupper(trim((string) ($params['source_currency'] ?? 'CNY')));
        if ($currency !== $expectedCurrency) {
            return false;
        }

        $sign = $params['sign'];
        unset($params['sign']);
        ksort($params);
        $str = http_build_query($params) . $secret;

        if (!hash_equals(md5($str), $sign)) {
            return false;
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no'],
            'paid_amount' => $paidAmount,
        ];
    }
}
