<?php

namespace Tests\Unit\Utils;

use App\Utils\AtomicRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AtomicRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_fixed_window_limit_does_not_grow_under_repeated_hits(): void
    {
        $allowed = 0;
        for ($i = 0; $i < 100; $i++) {
            if (AtomicRateLimiter::hit('test:atomic-rate-limit', 5, 60)) {
                $allowed++;
            }
        }

        $this->assertSame(5, $allowed);
        $this->assertSame(5, (int) Cache::get('test:atomic-rate-limit'));
    }

    public function test_releasing_a_failed_registration_restores_a_slot(): void
    {
        $this->assertTrue(AtomicRateLimiter::hit('test:atomic-release', 1, 60));
        AtomicRateLimiter::release('test:atomic-release');

        $this->assertTrue(AtomicRateLimiter::hit('test:atomic-release', 1, 60));
        $this->assertSame(1, (int) Cache::get('test:atomic-release'));
    }
}
