<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_admin_access_from_non_allowlisted_ip(): void
    {
        config()->set('admin.ip_allowlist', '10.99.99.99');
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/config/fetch';
        $this->getJson($path)->assertStatus(403);
    }
}
