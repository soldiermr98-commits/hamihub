<?php
declare(strict_types=1);

/**
 * نقطه ورود اصلی وب‌سایت عمومی HamiHubX.ir
 * تعریف ثابت BASE_PATH برای ساخت تمام مسیرهای پروژه
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Bootstrap::init(BASE_PATH);

$request = new Request();
$path = $request->path();

// اگر درخواست مربوط به مسیرهای API بود به کنترلر API هدایت شود
if (str_starts_with($path, '/api/')) {
    require BASE_PATH . '/public_html/api/index.php';
    exit;
}

// سرو از کش در صورت اجرای مستقیم از طریق PHP (در حالت عادی آپاچی مستقیم فایل کش را می‌دهد)
if ($request->method() === 'GET' && empty($_GET)) {
    $cachedHtml = Cache::getPage($path);
    if ($cachedHtml !== null) {
        Response::html($cachedHtml);
    }
}

$router = new Router();

// صفحه اصلی موقت در مرحله S0 با فونت لوکال وزیرمتن (در مرحله S4 با قالب کامل سئو جایگزین می‌شود)
$router->get('/', static function (Request $req): void {
    $html = '<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>همی‌هاب گیلان (HamiHubX.ir) — معرفی بهترین‌های استان گیلان</title>
  <link rel="preload" href="/assets/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/assets/fonts/Vazirmatn-Bold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/public.css">
</head>
<body>
  <div class="container" style="padding:4rem 1rem;text-align:center;">
    <h1>همی‌هاب گیلان (HamiHubX.ir)</h1>
    <p>پلتفرم خودکار معرفی بهترین کسب‌وکارهای استان گیلان — فونت لوکال وزیرمتن و کش سطح دیسک فعال است.</p>
    <p><a href="/admin/">ورود به پنل مدیریت (/admin/)</a> | <a href="/check.php?key=hhx-check-2026-key">گزارش سلامت سرور (check.php)</a></p>
  </div>
</body>
</html>';
    Cache::putPage('/', $html);
    Response::html($html);
});

$router->dispatch($request);
