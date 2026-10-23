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

    public function test_shared_file_cache_rate_limit_survives_parallel_workers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the multi-process cache-lock test.');
        }

        $previousDriver = app('cache')->getDefaultDriver();
        app('cache')->setDefaultDriver('file');

        $key = 'test:parallel-rate-limit:' . bin2hex(random_bytes(8));
        $resultDir = sys_get_temp_dir() . '/xboard-rate-limit-' . bin2hex(random_bytes(6));
        mkdir($resultDir, 0700, true);
        $children = [];

        try {
            Cache::forget($key);
            for ($i = 0; $i < 10; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Could not fork a rate-limit worker.');
                }
                if ($pid === 0) {
                    $allowed = AtomicRateLimiter::hit($key, 5, 60);
                    file_put_contents($resultDir . '/' . $i, $allowed ? '1' : '0');
                    exit(0);
                }
                $children[] = $pid;
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $allowed = 0;
            for ($i = 0; $i < 10; $i++) {
                $result = file_get_contents($resultDir . '/' . $i);
                $allowed += $result === '1' ? 1 : 0;
                unlink($resultDir . '/' . $i);
            }

            $this->assertSame(5, $allowed);
            $this->assertSame(5, (int) Cache::get($key));
        } finally {
            Cache::forget($key);
            app('cache')->setDefaultDriver($previousDriver);
            foreach (glob($resultDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
        }
    }

    public function test_releasing_a_failed_registration_restores_a_slot(): void
    {
        $this->assertTrue(AtomicRateLimiter::hit('test:atomic-release', 1, 60));
        AtomicRateLimiter::release('test:atomic-release');

        $this->assertTrue(AtomicRateLimiter::hit('test:atomic-release', 1, 60));
        $this->assertSame(1, (int) Cache::get('test:atomic-release'));
    }
}
