<?php
declare(strict_types=1);

/**
 * نقطه ورود اصلی وب‌سایت عمومی HamiHubX.ir (مرحله S4.3)
 * شامل سرو مستقیم robots.txt و sitemap.xml، کش HTML دیسک، قالب‌های سئو و اسکیماهای JSON-LD
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Controllers\PublicSiteController;
use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Bootstrap::init(BASE_PATH);

$request = new Request();
$path = $request->path();

// ۱. سرو فوری robots.txt و sitemap.xml قبل از هرگونه کش یا روتر برای تضمین صفر خطای 404
if ($path === '/robots.txt') {
    PublicSiteController::robotsTxt($request);
    exit;
}
if ($path === '/sitemap.xml') {
    PublicSiteController::sitemapXml($request);
    exit;
}

// ۲. اگر درخواست مربوط به مسیرهای API بود به کنترلر API هدایت شود
if (str_starts_with($path, '/api/')) {
    require BASE_PATH . '/public_html/api/index.php';
    exit;
}

// ۳. اگر درخواست مربوط به زیرمسیرهای پنل مدیریت (/admin/review, /admin/articles و غیره) بود، فایل SPA پنل سرو شود
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $adminIndexFile = __DIR__ . '/admin/index.html';
    if (is_file($adminIndexFile)) {
        Response::html((string) file_get_contents($adminIndexFile));
    }
}

// ۴. سرو از کش دیسک (public_html/cache/pages) در صورت معتبر بودن نسخه استایل S4.3
if ($request->method() === 'GET' && empty($_GET)) {
    $cachedHtml = Cache::getPage($path);
    if ($cachedHtml !== null) {
        if (!str_contains($cachedHtml, 'public.css?v=s4.3')) {
            Cache::flushAll();
        } else {
            Response::html($cachedHtml);
        }
    }
}

$router = new Router();

// ۵. مسیرهای عمومی سئو و قالب‌های مرحله S4
$router->get('/', [PublicSiteController::class, 'home']);
$router->get('/sitemap.xml', [PublicSiteController::class, 'sitemapXml']);
$router->get('/robots.txt', [PublicSiteController::class, 'robotsTxt']);
$router->get('/b/{slug}', [PublicSiteController::class, 'businessDetail']);
$router->get('/city/{city}', [PublicSiteController::class, 'cityHub']);
$router->get('/city/{city}/{category}', [PublicSiteController::class, 'cityCategory']);
$router->get('/{slug}', [PublicSiteController::class, 'articleDetail']);

$router->dispatch($request);
