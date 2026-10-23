<?php

return [
    // Optional comma-separated public IP/CIDR allowlist for admin API routes.
    // Empty disables IP restriction; set trusted proxies correctly when behind a reverse proxy.
    'ip_allowlist' => env('ADMIN_IP_ALLOWLIST', ''),
];
