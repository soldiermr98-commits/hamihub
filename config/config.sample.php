<?php
declare(strict_types=1);

/**
 * نمونه فایل تنظیمات اصلی پروژه HamiHubX.ir
 * در محیط عملیاتی این فایل را به config.php کپی کرده و مقادیر را تکمیل کنید.
 */

return [
    'app' => [
        'name' => 'حامی‌هاب گیلان (HamiHubX)',
        'url' => 'https://hamihubx.ir',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Tehran',
        // توکن یک‌بارمصرف برای اجرای install.php جهت ساخت اولین مدیر
        'install_token' => 'CHANGE_ME_RANDOM_INSTALL_TOKEN_32_CHARS',
        // کلید دسترسی به گزارش وضعیت سرور در check.php
        'check_key' => 'CHANGE_ME_CHECK_SECRET_KEY',
    ],
    'db' => [
        // در هاست اشتراکی DirectAdmin حتماً 'localhost' بماند تا از Unix Socket استفاده شود (نه 127.0.0.1)
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'liliflor_hamihubx',
        'username' => 'liliflor_user',
        'password' => 'STRONG_DB_PASSWORD_HERE',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'security' => [
        'session_name' => 'hhx_session',
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'csrf_header' => 'X-CSRF-Token',
        'login_max_attempts' => 5,
        'login_lockout_minutes' => 10,
        // توکن ارتباط با برنامه collector-remote روی کامپیوتر مدیر
        'collector_bearer_token' => 'CHANGE_ME_COLLECTOR_BEARER_TOKEN_64_CHARS',
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
