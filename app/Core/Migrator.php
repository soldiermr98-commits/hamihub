<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * سرویس اجرای افزایشی مایگریشن‌های SQL (قابل فراخوانی از وب، پنل ادمین، install.php و CLI)
 * بدون نیاز به دسترسی ترمینال یا SSH روی هاست اشتراکی DirectAdmin
 */
final class Migrator
{
    /**
     * اجرای تمام فایل‌های .sql داخل database/migrations که هنوز در جدول schema_migrations ثبت نشده‌اند
     *
     * @return array{ok: bool, applied: string[], skipped: string[], total_applied: int}
     */
    public static function runIncremental(): array
    {
        $pdo = DB::connection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `migration` VARCHAR(190) NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $executedRows = DB::fetchAll("SELECT migration FROM schema_migrations");
        $executedMap = [];
        foreach ($executedRows as $row) {
            $executedMap[(string) $row['migration']] = true;
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $migrationsDir = $base . '/database/migrations';
        $files = glob($migrationsDir . '/*.sql');
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        $applied = [];
        $skipped = [];

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            if (isset($executedMap[$fileName])) {
                $skipped[] = $fileName;
                continue;
            }

            $sql = file_get_contents($filePath);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("فایل مایگریشن خالی یا غیرقابل خواندن است: {$fileName}");
            }

            $pdo->exec($sql);
            DB::query(
                "INSERT INTO schema_migrations (migration, executed_at) VALUES (:m, NOW())",
                ['m' => $fileName]
            );
            $applied[] = $fileName;
        }

        return [
            'ok' => true,
            'applied' => $applied,
            'skipped' => $skipped,
            'total_applied' => count($applied),
        ];
    }
}
