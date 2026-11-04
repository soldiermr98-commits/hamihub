<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * مدیریت اتصال یکتای PDO به MySQL/MariaDB با منطقه زمانی +03:30 و utf8mb4
 * سازگار با هاست‌های اشتراکی DirectAdmin که پورت TCP 3306 را بسته و فقط از طریق Unix Socket (localhost) متصل می‌شوند.
 */
final class DB
{
    private static ?PDO $pdo = null;

    /**
     * دریافت نمونه اتصال فعال PDO
     */
    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = trim((string) Config::get('db.host', 'localhost'));
        $port = (int) Config::get('db.port', 3306);
        $database = (string) Config::get('db.database', '');
        $username = (string) Config::get('db.username', '');
        $password = (string) Config::get('db.password', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');
        $unixSocket = trim((string) Config::get('db.unix_socket', ''));

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // لیست DSNهای کاندید: اولویت با Unix Socket / localhost (بدون پورت TCP) برای سازگاری کامل با DirectAdmin
        $dsnCandidates = [];

        if ($unixSocket !== '') {
            $dsnCandidates[] = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $unixSocket, $database, $charset);
        }

        if ($host === 'localhost' || $host === '127.0.0.1') {
            // ۱. اتصال از طریق localhost بدون ذکر port (استفاده از Unix Socket پیش‌فرض سرور)
            $dsnCandidates[] = sprintf('mysql:host=localhost;dbname=%s;charset=%s', $database, $charset);
            // ۲. در صورتی که روی سیستم لوکال نیاز به TCP بود
            $dsnCandidates[] = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        } else {
            $dsnCandidates[] = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        }

        $lastException = null;
        foreach ($dsnCandidates as $dsn) {
            try {
                $pdo = new PDO($dsn, $username, $password, $options);

                // تنظیم اجباری منطقه زمانی تهران و کدگذاری در هر اتصال دیتابیس
                $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("SET time_zone = '+03:30'");

                self::$pdo = $pdo;
                return self::$pdo;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        throw new RuntimeException(
            'خطا در اتصال به پایگاه داده: ' . ($lastException ? $lastException->getMessage() : 'اتصال برقرار نشد'),
            (int) ($lastException ? $lastException->getCode() : 0),
            $lastException
        );
    }

    /**
     * اجرای کوئری با Prepared Statement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * دریافت تمام رکوردهای نتیجه
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * دریافت یک رکورد
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * دریافت آخرین شناسه درج‌شده
     */
    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }
}
