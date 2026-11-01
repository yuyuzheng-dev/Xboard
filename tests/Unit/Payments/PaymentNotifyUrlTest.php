<?php
namespace Tests\Unit\Payments;

use App\Exceptions\ApiException;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentNotifyUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_host_comes_from_config_not_request_host(): void
    {
        admin_setting(['app_url' => 'https://panel.example.com']);
        $request = \Illuminate\Http\Request::create('https://attacker.example/checkout', 'POST', [], [], [],
            ['HTTP_X_FORWARDED_HOST' => 'attacker.example']);
        app()->instance('request', $request);
        $this->assertSame('https://panel.example.com/api/v1/guest/payment/notify/EPay/abc',
            PaymentService::notifyUrl('EPay', 'abc'));
        $this->assertSame('https://notify.example.com:8443/api/v1/guest/payment/notify/EPay/abc',
            PaymentService::notifyUrl('EPay', 'abc', 'https://notify.example.com:8443/'));
    }

    public function test_callback_url_encodes_gateway_segments(): void
    {
        $this->assertSame('https://notify.example.com/api/v1/guest/payment/notify/A%2FB/space%20ok',
            PaymentService::notifyUrl('A/B', 'space ok', 'https://notify.example.com'));
    }

    public function test_callback_url_rejects_untrusted_schemes_credentials_and_paths(): void
    {
        foreach ([
            'javascript:alert(1)', 'https://user:pass@example.com', 'https://example.com/elsewhere',
            'https://example.com/?redirect=bad', 'https://example.com/#fragment',
            '//example.com', 'ftp://example.com', 'https://',
        ] as $candidate) {
            try {
                PaymentService::notifyUrl('EPay', 'abc', $candidate);
                $this->fail('Accepted unsafe callback base: ' . $candidate);
            } catch (ApiException $expected) {
                $this->assertTrue(true);
            }
        }
    }
}
