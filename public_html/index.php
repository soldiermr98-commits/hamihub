<?php
declare(strict_types=1);

/**
 * نقطه ورود اصلی وب‌سایت عمومی HamiHubX.ir (مرحله S4)
 * شامل سرو فوق‌سریع کش HTML از دیسک، قالب‌های سئو، اسکیماهای JSON-LD، نقشه سایت و Robots.txt
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

// ۱. اگر درخواست مربوط به مسیرهای API بود به کنترلر API هدایت شود
if (str_starts_with($path, '/api/')) {
    require BASE_PATH . '/public_html/api/index.php';
    exit;
}

// ۲. اگر درخواست مربوط به زیرمسیرهای پنل مدیریت (/admin/review, /admin/articles و غیره) بود، فایل SPA پنل سرو شود
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $adminIndexFile = __DIR__ . '/admin/index.html';
    if (is_file($adminIndexFile)) {
        Response::html((string) file_get_contents($adminIndexFile));
    }
}

// ۳. سرو از کش دیسک (public_html/cache/pages) در صورت اجرای مستقیم از طریق PHP
if ($request->method() === 'GET' && empty($_GET) && $path !== '/sitemap.xml' && $path !== '/robots.txt') {
    $cachedHtml = Cache::getPage($path);
    if ($cachedHtml !== null) {
        Response::html($cachedHtml);
    }
}

$router = new Router();

// ۴. مسیرهای عمومی سئو، نقشه سایت، Robots.txt و قالب‌های مرحله S4
$router->get('/', [PublicSiteController::class, 'home']);
$router->get('/sitemap.xml', [PublicSiteController::class, 'sitemapXml']);
$router->get('/robots.txt', [PublicSiteController::class, 'robotsTxt']);
$router->get('/b/{slug}', [PublicSiteController::class, 'businessDetail']);
$router->get('/city/{city}', [PublicSiteController::class, 'cityHub']);
$router->get('/city/{city}/{category}', [PublicSiteController::class, 'cityCategory']);
$router->get('/{slug}', [PublicSiteController::class, 'articleDetail']);

$router->dispatch($request);
