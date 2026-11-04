<?php
declare(strict_types=1);

/**
 * اسکریپت کرون جاب ۵ دقیقه‌ای سرور (cron/worker.php)
 * در مرحله S0 اسکلت پایه و ثبت لاگ سلامت کرون را انجام می‌دهد
 * و در مرحله S6 ابطال زمان‌بندی‌شده کش جایگاه‌های ویژه (sponsor_start / sponsor_end) به آن اضافه می‌شود.
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Core\Bootstrap;

Bootstrap::init(BASE_PATH);

$logDir = BASE_PATH . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$timestamp = date('Y-m-d H:i:s P');
@file_put_contents(
    $logDir . '/worker.log',
    "[{$timestamp}] اجرای موفق cron/worker.php (S0 Heartbeat)\n",
    FILE_APPEND
);

echo "[{$timestamp}] worker.php executed.\n";
