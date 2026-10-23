<?php

namespace Tests\Unit\Payments;

use App\Utils\PaymentAmount;
use PHPUnit\Framework\TestCase;

class PaymentAmountTest extends TestCase
{
    public function test_parses_exact_decimal_cents(): void
    {
        $this->assertSame(1230, PaymentAmount::toCents('12.30'));
        $this->assertSame(1200, PaymentAmount::toCents('12'));
        $this->assertSame(1205, PaymentAmount::toCents('12.05'));
        $this->assertSame(1230, PaymentAmount::toCents(12.3));
    }

    public function test_rejects_unsafe_values(): void
    {
        foreach (['-1', '12.345', '1e3', 'NaN', '00.01', [], null] as $invalid) {
            $this->assertNull(PaymentAmount::toCents($invalid));
        }
    }
}
