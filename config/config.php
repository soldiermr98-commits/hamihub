<?php
declare(strict_types=1);

/**
 * فایل تنظیمات فعال پروژه HamiHubX.ir
 * توجه: در هاست اشتراکی DirectAdmin حتماً host روی 'localhost' باشد تا از Unix Socket استفاده شود.
 */

return [
    'app' => [
        'name' => 'حامی‌هاب گیلان (HamiHubX)',
        'url' => 'https://hamihubx.ir',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Tehran',
        'install_token' => 'hhx-install-2026-secret-token',
        'check_key' => 'hhx-check-2026-key',
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'hamihubx_db',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'security' => [
        'session_name' => 'hhx_session',
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'csrf_header' => 'X-CSRF-Token',
        'login_max_attempts' => 5,
        'login_lockout_minutes' => 10,
        'collector_bearer_token' => 'hhx-collector-bearer-token-local-dev',
    ],
    'reserved_slugs' => [
        'admin',
        'api',
        'assets',
        'uploads',
        'cache',
        'city',
        'b',
        'sitemap',
        'robots',
        'install',
        'check',
        'storage',
    ],
];
