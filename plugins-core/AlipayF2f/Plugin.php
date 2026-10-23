<?php

namespace Plugin\AlipayF2f;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Utils\PaymentAmount;
use Illuminate\Support\Facades\Log;
use Plugin\AlipayF2f\library\AlipayF2F;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['AlipayF2F'] = [
                    'name' => $this->getConfig('display_name', '支付宝当面付'),
                    'icon' => $this->getConfig('icon', '💙'),
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
            'app_id' => [
                'label' => '支付宝APPID',
                'type' => 'string',
                'required' => true,
                'description' => '支付宝开放平台应用的APPID'
            ],
            'private_key' => [
                'label' => '支付宝私钥',
                'type' => 'text',
                'required' => true,
                'description' => '应用私钥，用于签名'
            ],
            'public_key' => [
                'label' => '支付宝公钥',
                'type' => 'text',
                'required' => true,
                'description' => '支付宝公钥，用于验签'
            ],
            'product_name' => [
                'label' => '自定义商品名称',
                'type' => 'string',
                'description' => '将会体现在支付宝账单中'
            ]
        ];
    }

    public function pay($order): array
    {
        try {
            $gateway = new AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->getConfig('app_id'));
            $gateway->setPrivateKey($this->getConfig('private_key'));
            $gateway->setAlipayPublicKey($this->getConfig('public_key'));
            $gateway->setNotifyUrl($order['notify_url']);
            $gateway->setBizContent([
                'subject' => $this->getConfig('product_name') ?? (admin_setting('app_name', 'XBoard') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['total_amount'] / 100
            ]);
            $gateway->send();
            return [
                'type' => 0,
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Exception $e) {
            Log::error($e);
            throw new ApiException($e->getMessage());
        }
    }

    public function notify($params): array|bool
    {
        if (!is_array($params) || ($params['trade_status'] ?? null) !== 'TRADE_SUCCESS'
            || !is_string($params['app_id'] ?? null)
            || (string) $params['app_id'] !== (string) $this->getConfig('app_id')
            || !is_string($params['out_trade_no'] ?? null)
            || trim($params['out_trade_no']) === ''
            || !is_string($params['trade_no'] ?? null)
            || trim($params['trade_no']) === '') {
            return false;
        }

        $paidAmount = PaymentAmount::toCents($params['total_amount'] ?? null);
        if ($paidAmount === null || $paidAmount <= 0) {
            return false;
        }

        $gateway = new AlipayF2F();
        $gateway->setAppId($this->getConfig('app_id'));
        $gateway->setPrivateKey($this->getConfig('private_key'));
        $gateway->setAlipayPublicKey($this->getConfig('public_key'));

        try {
            if (!$gateway->verify($params)) {
                return false;
            }
            return [
                'trade_no' => $params['out_trade_no'],
                'callback_no' => $params['trade_no'],
                'paid_amount' => $paidAmount,
            ];
        } catch (\Throwable $e) {
            return false;
        }
    }
}
