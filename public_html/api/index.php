<?php
declare(strict_types=1);

/**
 * نقطه ورود تمام درخواست‌های API نسخه ۱ (/api/v1/*)
 * شامل مسیرهای مراحل S0، S1، S2، S3 و S4 (مقالات لیستی، اسکیما و رهگیری رویدادها)
 */

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Controllers\ArticleController;
use App\Controllers\AuthController;
use App\Controllers\BusinessController;
use App\Controllers\CategoryController;
use App\Controllers\CityController;
use App\Controllers\CollectorController;
use App\Controllers\PublicSiteController;
use App\Controllers\ReviewQueueController;
use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Router;

Bootstrap::init(BASE_PATH);

// پاک‌سازی خودکار کش قدیمی در صورت عدم تطابق با استایل جدید S4.3 (تم آبی لوگو و لاجوردی)
$homeCache = Cache::getPage('/');
if ($homeCache !== null && !str_contains($homeCache, 'public.css?v=s4.3')) {
    Cache::flushAll();
}

$request = new Request();
$router = new Router();

// ۱. مسیرهای احراز هویت و عملیات سیستمی بدون ترمینال (S0)
$router->get('/api/v1/auth/me', [AuthController::class, 'me']);
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/v1/cache/flush', [AuthController::class, 'flushCache']);
$router->post('/api/v1/system/migrate', [AuthController::class, 'runMigrations']);

// ۲. مسیرهای CRUD شهر، دسته، بسته دسته، کسب‌وکار و رسانه WebP (S1)
$router->get('/api/v1/cities', [CityController::class, 'index']);
$router->post('/api/v1/cities', [CityController::class, 'storeOrUpdate']);
$router->delete('/api/v1/cities/{id}', [CityController::class, 'destroy']);

$router->get('/api/v1/categories', [CategoryController::class, 'index']);
$router->post('/api/v1/categories', [CategoryController::class, 'storeOrUpdateCategory']);
$router->post('/api/v1/category-packs', [CategoryController::class, 'storeOrUpdatePack']);

$router->get('/api/v1/businesses', [BusinessController::class, 'index']);
$router->post('/api/v1/businesses', [BusinessController::class, 'storeOrUpdate']);
$router->post('/api/v1/media/upload', [BusinessController::class, 'uploadMedia']);
$router->get('/api/v1/slug/suggest', [BusinessController::class, 'suggestSlug']);

// ۳. مسیرهای صف جمع‌آوری خودکار (پنل ادمین + کلاینت collector-remote در مرحله S2)
$router->get('/api/v1/collector/jobs', [CollectorController::class, 'index']);
$router->post('/api/v1/collector/jobs', [CollectorController::class, 'createJob']);
$router->post('/api/v1/collector/providers', [CollectorController::class, 'updateProvider']);
$router->post('/api/v1/collector/token', [CollectorController::class, 'updateToken']);

$router->get('/api/v1/collector/jobs/next', [CollectorController::class, 'pullNextJob']);
$router->post('/api/v1/collector/ingest', [CollectorController::class, 'ingest']);
$router->post('/api/v1/collector/photo', [CollectorController::class, 'uploadCollectorPhoto']);

// ۴. مسیرهای صندوق بازبینی، تاریخچه تغییرات و پایپ‌لاین ۱۰۰ رکورد نمونه (مرحله S3)
$router->get('/api/v1/review-queue', [ReviewQueueController::class, 'index']);
$router->post('/api/v1/review-queue/decide', [ReviewQueueController::class, 'decide']);
$router->post('/api/v1/pipeline/benchmark-100', [ReviewQueueController::class, 'runBenchmark100']);

// ۵. مسیرهای مدیریت مقالات لیستی سئو و رهگیری کلیک‌های عمومی (مرحله S4)
$router->get('/api/v1/articles', [ArticleController::class, 'index']);
$router->post('/api/v1/articles', [ArticleController::class, 'storeOrUpdate']);
$router->post('/api/v1/articles/auto-build', [ArticleController::class, 'autoBuild']);
$router->post('/api/v1/events/track', [PublicSiteController::class, 'trackEvent']);

$router->dispatch($request);
