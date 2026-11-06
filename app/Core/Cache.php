<?php
declare(strict_types=1);

namespace App\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * مدیریت کش صفحه کامل (Full-Page Cache) در مسیر public_html/cache/pages/<path>/index.html
 */
final class Cache
{
    private static function baseCacheDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . '/public_html/cache/pages';
    }

    /**
     * تبدیل مسیر URL به مسیر فایل کش استاندارد
     * صفحه اصلی در _home/index.html و سایر مسیرها در <clean-path>/index.html ذخیره می‌شوند
     */
    public static function resolveFilePath(string $uriPath): string
    {
        $clean = trim($uriPath, '/');
        if ($clean === '') {
            return self::baseCacheDir() . '/_home/index.html';
        }

        // فقط کاراکترهای مجاز مسیر اسلاگ انگلیسی و اسلش
        $safePath = preg_replace('/[^a-z0-9\/\-]/', '', strtolower($clean));
        return self::baseCacheDir() . '/' . trim((string) $safePath, '/') . '/index.html';
    }

    /**
     * ذخیره خروجی HTML در کش فایل
     */
    public static function putPage(string $uriPath, string $html): bool
    {
        $filePath = self::resolveFilePath($uriPath);
        $dir = dirname($filePath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($filePath, $html, LOCK_EX) !== false;
    }

    /**
     * خواندن صفحه از کش در صورت وجود
     */
    public static function getPage(string $uriPath): ?string
    {
        $filePath = self::resolveFilePath($uriPath);
        if (!is_file($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        // Ignore legacy pages built before the current public template and policy.
        if (!is_string($content) || !str_contains($content, 'public.css?v=s8.0')) return null;
        return $content;
    }

    /**
     * ابطال کش یک مسیر مشخص
     */
    public static function forgetPage(string $uriPath): bool
    {
        $filePath = self::resolveFilePath($uriPath);
        if (is_file($filePath)) {
            return @unlink($filePath);
        }
        return true;
    }

    /**
     * پاک‌سازی کامل تمام صفحات کش‌شده
     */
    public static function flushAll(): int
    {
        $dir = self::baseCacheDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $deletedCount = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'index.html') {
                if (@unlink($file->getRealPath())) {
                    $deletedCount++;
                }
            } elseif ($file->isDir()) {
                @rmdir($file->getRealPath());
            }
        }

        return $deletedCount;
    }
}
