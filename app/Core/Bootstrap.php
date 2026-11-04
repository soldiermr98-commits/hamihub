<?php
declare(strict_types=1);

namespace App\Core;

/**
 * بارگذار خودکار کلاس‌های فضای نام App\ بدون نیاز به Composer روی سرور هاست اشتراکی
 */
final class Bootstrap
{
    public static function init(string $basePath): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', rtrim($basePath, '/\\'));
        }

        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';
            $baseDir = BASE_PATH . '/app/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });

        $configFile = BASE_PATH . '/config/config.php';
        if (!is_file($configFile)) {
            $configFile = BASE_PATH . '/config/config.sample.php';
        }

        Config::load($configFile);
    }
}
