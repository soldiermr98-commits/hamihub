<?php
declare(strict_types=1);

/**
 * نقطه ورود تمام درخواست‌های API نسخه ۱ (/api/v1/*)
 * شامل مسیرهای مراحل S0، S1، S2، S3 و S4 (مقالات لیستی، اسکیما و رهگیری رویدادها)
 */

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Controllers\AdvertisingController;
use App\Controllers\AiArticleController;
use App\Controllers\AiWorkerController;
use App\Controllers\ApifyController;
use App\Controllers\ArticleController;
use App\Controllers\AuthController;
use App\Controllers\BusinessController;
use App\Controllers\CategoryController;
use App\Controllers\CityController;
use App\Controllers\CollectorController;
use App\Controllers\ManualImportController;
use App\Controllers\PublicSiteController;
use App\Controllers\ReviewQueueController;
use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Router;

Bootstrap::init(BASE_PATH);

$request = new Request();
$router = new Router();

// ۱. مسیرهای احراز هویت و عملیات سیستمی بدون ترمینال (S0)
$router->get('/api/v1/auth/me', [AuthController::class, 'me']);
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/v1/cache/flush', [AuthController::class, 'flushCache']);
$router->post('/api/v1/system/migrate', [AuthController::class, 'runMigrations']);
$router->post('/api/v1/system/update-article-schema', [AuthController::class, 'updateArticleSchema']);
$router->post('/api/v1/system/update-feature-schema', [AuthController::class, 'updateFeatureSchema']);

// ۲. مسیرهای CRUD شهر، دسته، بسته دسته، کسب‌وکار و رسانه WebP (S1)
$router->get('/api/v1/cities', [CityController::class, 'index']);
$router->post('/api/v1/cities', [CityController::class, 'storeOrUpdate']);
$router->delete('/api/v1/cities/{id}', [CityController::class, 'destroy']);

$router->get('/api/v1/categories', [CategoryController::class, 'index']);
$router->post('/api/v1/categories', [CategoryController::class, 'storeOrUpdateCategory']);
$router->post('/api/v1/category-packs', [CategoryController::class, 'storeOrUpdatePack']);

$router->get('/api/v1/businesses', [BusinessController::class, 'index']);
$router->post('/api/v1/businesses', [BusinessController::class, 'storeOrUpdate']);
$router->post('/api/v1/businesses/promotion', [BusinessController::class, 'setPromotion']);
$router->post('/api/v1/businesses/data-review', [BusinessController::class, 'recordDataReview']);
$router->post('/api/v1/import/json/prepare', [ManualImportController::class, 'prepare']);
$router->post('/api/v1/import/json/preview', [ManualImportController::class, 'preview']);
$router->post('/api/v1/import/json/apply', [ManualImportController::class, 'apply']);
$router->get('/api/v1/import/apify/status', [ApifyController::class, 'status']);
$router->post('/api/v1/import/apify/schedule', [ApifyController::class, 'saveSchedule']);
$router->post('/api/v1/import/apify/start', [ApifyController::class, 'startDirect']);
$router->post('/api/v1/import/apify/latest', [ApifyController::class, 'importLatest']);
$router->post('/api/v1/import/apify/run', [ApifyController::class, 'importRun']);
$router->get('/api/v1/import/apify/runs/{id}', [ApifyController::class, 'run']);
$router->post('/api/v1/import/apify/refresh', [ApifyController::class, 'refreshRun']);
$router->post('/api/v1/import/apify/delete', [ApifyController::class, 'deleteRun']);
$router->post('/api/v1/import/apify/resolve', [ApifyController::class, 'resolve']);
$router->post('/api/v1/media/upload', [BusinessController::class, 'uploadMedia']);
$router->get('/api/v1/slug/suggest', [BusinessController::class, 'suggestSlug']);

// ۳. مسیرهای صف جمع‌آوری خودکار (پنل ادمین + کلاینت collector-remote در مرحله S2)
$router->get('/api/v1/collector/jobs', [CollectorController::class, 'index']);
$router->post('/api/v1/collector/jobs', [CollectorController::class, 'createJob']);
$router->post('/api/v1/collector/providers', [CollectorController::class, 'updateProvider']);
$router->post('/api/v1/collector/run-next', [CollectorController::class, 'runNext']);
$router->post('/api/v1/collector/token', [CollectorController::class, 'remoteDisabled']);

$router->get('/api/v1/collector/jobs/next', [CollectorController::class, 'remoteDisabled']);
$router->post('/api/v1/collector/ingest', [CollectorController::class, 'remoteDisabled']);
$router->post('/api/v1/collector/photo', [CollectorController::class, 'remoteDisabled']);

// ۴. مسیرهای صندوق بازبینی، تاریخچه تغییرات و پایپ‌لاین ۱۰۰ رکورد نمونه (مرحله S3)
$router->get('/api/v1/review-queue', [ReviewQueueController::class, 'index']);
$router->post('/api/v1/review-queue/decide', [ReviewQueueController::class, 'decide']);
$router->post('/api/v1/pipeline/benchmark-100', [ReviewQueueController::class, 'runBenchmark100']);

// ۵. مسیرهای مدیریت مقالات لیستی سئو و رهگیری کلیک‌های عمومی (مرحله S4)
$router->get('/api/v1/articles', [ArticleController::class, 'index']);
$router->get('/api/v1/articles/preview/{id}', [PublicSiteController::class, 'articlePreview']);
$router->post('/api/v1/articles', [ArticleController::class, 'storeOrUpdate']);
$router->post('/api/v1/articles/auto-build', [ArticleController::class, 'autoBuild']);
$router->post('/api/v1/events/track', [PublicSiteController::class, 'trackEvent']);

// Independent banner inventory and advertiser inquiries (admin-only management).
$router->get('/api/v1/advertising', [AdvertisingController::class, 'index']);
$router->post('/api/v1/advertising/banner', [AdvertisingController::class, 'saveBanner']);
$router->post('/api/v1/advertising/banner/delete', [AdvertisingController::class, 'deleteBanner']);
$router->post('/api/v1/advertising/inquiry', [AdvertisingController::class, 'updateInquiry']);
$router->post('/api/v1/public/advertising-inquiry', [AdvertisingController::class, 'submitInquiry']);

// The old batch/worker mutation routes stay disabled; this new path is manual and draft-only.
$router->get('/api/v1/ai/article/providers', [AiArticleController::class, 'status']);
$router->post('/api/v1/ai/article/generate', [AiArticleController::class, 'generate']);

// ۶. مسیرهای صف کارهای هوش مصنوعی بدون توهم و ورکر راه دور (مرحله S5)
$router->get('/api/v1/ai/tasks', [AiWorkerController::class, 'index']);
$router->post('/api/v1/ai/tasks/enqueue', [AiWorkerController::class, 'disabled']);
$router->post('/api/v1/ai/tasks/run-batch', [AiWorkerController::class, 'disabled']);
$router->get('/api/v1/ai/tasks/next', [AiWorkerController::class, 'disabled']);
$router->post('/api/v1/ai/tasks/complete', [AiWorkerController::class, 'disabled']);

// ۷. مسیرهای مرحله S6 (ادعای مالکیت، گزارش خطا، آمار کلیک‌های روزانه و کرون ۵ دقیقه‌ای)
$router->post('/api/v1/public/claim', [PublicSiteController::class, 'submitClaim']);
$router->post('/api/v1/public/report', [PublicSiteController::class, 'submitReport']);
$router->get('/api/v1/analytics/summary', [PublicSiteController::class, 'analyticsSummary']);
$router->post('/api/v1/system/cron-run', [PublicSiteController::class, 'runCronMaintenance']);

$router->dispatch($request);
