<?php
declare(strict_types=1);

// Run from CLI only; schedule this file using the PHP binary provided by the host.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$basePath = dirname(__DIR__);
require_once $basePath . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init($basePath);
try {
    $summary = \App\Services\MaintenanceService::run();
    if (class_exists(\App\Services\CollectorRunner::class)) {
        $summary['collector'] = \App\Services\CollectorRunner::runNext();
    }
    try {
        $summary['apify'] = \App\Services\ApifyAutomationService::configured()->tick();
    } catch (Throwable $e) {
        error_log('Apify cron failed: ' . get_class($e));
        $summary['apify'] = ['status'=>'error'];
    }
    $ok = ($summary['collector']['status'] ?? '') !== 'failed'
        && !in_array($summary['apify']['status'] ?? '', ['error','fetch_error','preflight_error','start_unknown'], true);
    echo json_encode(['ok' => $ok, 'summary' => $summary], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!$ok) exit(1);
} catch (Throwable $e) {
    error_log('HamiHub cron failed: ' . $e->getMessage());
    fwrite(STDERR, "Cron failed; see server logs.\n");
    exit(1);
}
