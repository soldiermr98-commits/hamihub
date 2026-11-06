<?php
declare(strict_types=1);

/**
 * HamiHubX.ir - ورکر زمان‌بندی‌شده ۵ دقیقه‌ای (مرحله S6 — کامل)
 *
 * دستور تنظیم در DirectAdmin -> Cron Jobs (هر ۵ دقیقه):
 * /usr/local/bin/php /home/liliflor/domains/hamihubx.ir/public_html/cron/worker.php >/dev/null 2>&1
 *
 * وظایف این ورکر در مرحله S6:
 * ۱. بررسی بازه‌های زمانی جایگاه‌های اسپانسری (sponsor_start / sponsor_end) در جدول article_items
 *    نسبت به آخرین زمان بررسی (settings.sponsor_last_check) و پاک‌سازی خودکار کش صفحات در صورت شروع یا انقضای اسپانسر.
 * ۲. آزادسازی Jobهای جمع‌آوری (collector_jobs) و تسک‌های هوش مصنوعی (ai_tasks) که بیش از ۱۵ دقیقه در وضعیت claimed/running قفل مانده‌اند.
 * ۳. پاک‌سازی رکوردهای قدیمی جدول login_attempts (بیش از ۲۴ ساعت).
 * ۴. ثبت لاگ ساختاریافته در storage/logs/cron.log.
 */

$basePath = is_file(dirname(__DIR__) . '/app/Core/Bootstrap.php')
    ? dirname(__DIR__)
    : dirname(__DIR__, 2);

require_once $basePath . '/app/Core/Bootstrap.php';

use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\DB;
use App\Core\Logger;

Bootstrap::init($basePath);

$now = date('Y-m-d H:i:s');
$summary = [
    'checked_at'          => $now,
    'sponsor_transitions' => 0,
    'cache_flushed'       => false,
    'released_jobs'       => 0,
    'released_ai_tasks'   => 0,
    'cleaned_logins'      => 0,
];

try {
    if (DB::isConnected()) {
        // ۱. بررسی تغییر وضعیت جایگاه‌های اسپانسری (شروع یا پایان بازه زمانی)
        $lastCheckRow = DB::fetchOne("SELECT `value` FROM settings WHERE `key` = 'sponsor_last_check' LIMIT 1");
        $lastCheck = !empty($lastCheckRow['value']) ? (string)$lastCheckRow['value'] : '2000-01-01 00:00:00';

        $crossedSponsors = DB::fetchAll(
            "SELECT ai.id, ai.article_id, ai.business_id, ai.sponsor_start, ai.sponsor_end
             FROM article_items ai
             WHERE ai.is_sponsored = 1
               AND (
                   (ai.sponsor_start IS NOT NULL AND ai.sponsor_start > :lc1 AND ai.sponsor_start <= :now1)
                   OR
                   (ai.sponsor_end IS NOT NULL AND ai.sponsor_end > :lc2 AND ai.sponsor_end <= :now2)
               )",
            [
                'lc1'  => $lastCheck,
                'now1' => $now,
                'lc2'  => $lastCheck,
                'now2' => $now,
            ]
        );

        $summary['sponsor_transitions'] = count($crossedSponsors);
        if ($summary['sponsor_transitions'] > 0) {
            Cache::flushAll();
            $summary['cache_flushed'] = true;
        }

        DB::query(
            "INSERT INTO settings (`key`, `value`, updated_at)
             VALUES ('sponsor_last_check', :val, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()",
            ['val' => $now]
        );

        // ۲. آزادسازی Jobهای جمع‌آوری گیرکرده بیش از ۱۵ دقیقه
        $stuckJobsStmt = DB::query(
            "UPDATE collector_jobs
             SET status = 'queued', claimed_at = NULL, error = 'آزادسازی خودکار توسط کرون S6 (Timeout > 15m)', updated_at = NOW()
             WHERE status IN ('claimed', 'running')
               AND claimed_at IS NOT NULL
               AND claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $summary['released_jobs'] = $stuckJobsStmt->rowCount();

        // ۳. آزادسازی تسک‌های هوش مصنوعی گیرکرده بیش از ۱۵ دقیقه
        $stuckAiStmt = DB::query(
            "UPDATE ai_tasks
             SET status = 'queued', updated_at = NOW()
             WHERE status = 'claimed'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $summary['released_ai_tasks'] = $stuckAiStmt->rowCount();

        // ۴. پاک‌سازی تلاش‌های ورود قدیمی‌تر از ۲۴ ساعت
        $loginStmt = DB::query(
            "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $summary['cleaned_logins'] = $loginStmt->rowCount();
    }

    Logger::cron('S6 cron worker executed successfully', $summary);

    if (PHP_SAPI === 'cli') {
        echo '[OK] HamiHubX S6 Cron Worker @ ' . $now . ' | ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
} catch (Throwable $e) {
    Logger::error('S6 cron worker error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
