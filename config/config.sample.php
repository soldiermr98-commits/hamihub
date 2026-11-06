<?php
declare(strict_types=1);

// Copy to config.local.php and fill in private values on the host.
return [
    'app' => [
        'name' => 'حامی‌هاب گیلان',
        'url' => 'https://hamihubx.ir',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Tehran',
        'install_token' => '', // random secret, at least 24 characters
        'check_key' => '',     // a different random secret, at least 24 characters
    ],
    'db' => [
        'host' => 'localhost', 'port' => 3306, 'database' => '', 'username' => '', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ],
    'security' => [
        'session_name' => 'hhx_session', 'cookie_secure' => true, 'cookie_httponly' => true,
        'cookie_samesite' => 'Lax', 'csrf_header' => 'X-CSRF-Token',
        'login_max_attempts' => 5, 'login_lockout_minutes' => 10, 'collector_bearer_token' => '',
    ],
    'providers' => [
        'osm' => ['overpass_endpoint' => 'https://overpass-api.de/api/interpreter', 'user_agent' => 'HamiHubX/1.0 (https://hamihubx.ir)'],
        // Use only after verifying API terms, quota, permissions, and live connectivity.
        'neshan' => ['api_key' => '', 'search_url' => 'https://api.neshan.org/v1/search'],
    ],
    'reserved_slugs' => ['admin','api','assets','uploads','cache','city','b','sitemap','robots','install','check','storage'],
];
