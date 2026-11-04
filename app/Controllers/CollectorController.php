<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\MediaService;
use App\Services\SlugService;
use Throwable;

/**
 * کنترلر مدیریت صف جمع‌آوری خودکار (مدل Pull - مرحله S2)
 * شامل:
 * ۱. اندپوینت‌های پنل مدیریت برای ساخت Job، مشاهده پیشرفت، مدیریت پرووایدرها و توکن Bearer
 * ۲. اندپوینت‌های ارتباط با collector-remote روی کامپیوتر مدیر (GET jobs/next, POST ingest, POST photo)
 */
final class CollectorController
{
    private const MAX_INGEST_BYTES = 1024 * 1024; // حداکثر ۱ مگابایت
    private const MAX_INGEST_ITEMS = 200; // حداکثر ۲۰۰ آیتم در هر درخواست ingest

    /**
     * وضعیت کامل صف Jobها، وضعیت پرووایدرها و توکن Bearer برای نمایش در پنل ادمین
     * GET /api/v1/collector/jobs
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $jobs = DB::fetchAll(
            "SELECT j.*,
                    c.name_fa AS city_name,
                    c.slug AS city_slug,
                    p.name AS pack_name
             FROM collector_jobs j
             LEFT JOIN cities c ON c.id = j.city_id
             LEFT JOIN category_packs p ON p.id = j.pack_id
             ORDER BY j.id DESC
             LIMIT 100"
        );

        $providers = DB::fetchAll("SELECT * FROM provider_state ORDER BY priority ASC");
        $tokenRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'collector_bearer_token' LIMIT 1");
        $photoPolicyRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'photo_policy' LIMIT 1");

        Response::json([
            'ok' => true,
            'jobs' => $jobs,
            'providers' => $providers,
            'collector_bearer_token' => $tokenRow['v'] ?? '',
            'photo_policy' => $photoPolicyRow['v'] ?? 'none',
        ]);
    }

    /**
     * ثبت Job جدید در صف (مثلاً شهر «رشت» + بسته دسته «کافه و رستوران»)
     * POST /api/v1/collector/jobs
     */
    public static function createJob(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        $cityId = (int) $request->input('city_id', 0);
        $packId = (int) $request->input('pack_id', 0);
        $provider = strtolower(trim((string) $request->input('provider', 'auto')));
        $customQuery = SlugService::normalizePersianText((string) $request->input('custom_query', ''));
        $categoryId = (int) $request->input('category_id', 1);

        if ($cityId <= 0) {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً شهر هدف برای جمع‌آوری را انتخاب کنید.',
                'field' => 'city_id',
            ], 422);
        }

        $city = DB::fetchOne("SELECT * FROM cities WHERE id = :id", ['id' => $cityId]);
        if ($city === null) {
            Response::json(['ok' => false, 'error' => 'شهر انتخاب‌شده در دیتابیس یافت نشد.'], 404);
        }

        // اگر پرووایدر auto بود، اولین پرووایدر فعال و متوقف‌نشده بر اساس اولویت انتخاب شود
        if ($provider === 'auto' || $provider === '') {
            $activeProvider = DB::fetchOne(
                "SELECT provider FROM provider_state
                 WHERE enabled = 1
                   AND (paused_until IS NULL OR paused_until < NOW())
                 ORDER BY priority ASC
                 LIMIT 1"
            );
            $provider = (string) ($activeProvider['provider'] ?? 'osm');
        }

        $pack = $packId > 0 ? DB::fetchOne("SELECT * FROM category_packs WHERE id = :id", ['id' => $packId]) : null;
        $packQueries = [];
        if ($pack !== null && !empty($pack['queries_json'])) {
            $decoded = json_decode((string) $pack['queries_json'], true);
            if (is_array($decoded)) {
                $packQueries = $decoded;
            }
        }

        $packItem = $packId > 0
            ? DB::fetchOne("SELECT category_id FROM category_pack_items WHERE pack_id = :pid LIMIT 1", ['pid' => $packId])
            : null;
        if ($packItem !== null && !empty($packItem['category_id'])) {
            $categoryId = (int) $packItem['category_id'];
        }

        $params = [
            'city_name_fa' => $city['name_fa'],
            'city_slug' => $city['slug'],
            'lat' => (float) ($city['lat'] ?? 37.2808),
            'lng' => (float) ($city['lng'] ?? 49.5830),
            'bbox' => json_decode((string) ($city['bbox_json'] ?? '[37.19,49.48,37.36,49.68]'), true),
            'category_id' => $categoryId,
            'queries_by_provider' => $packQueries,
            'custom_query' => $customQuery,
        ];

        DB::query(
            "INSERT INTO collector_jobs (city_id, pack_id, provider, params_json, status, attempts, created_at, updated_at)
             VALUES (:cid, :pid, :prov, :params, 'queued', 0, NOW(), NOW())",
            [
                'cid' => $cityId,
                'pid' => $packId > 0 ? $packId : null,
                'prov' => $provider,
                'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            ]
        );

        $jobId = DB::lastInsertId();
        $job = DB::fetchOne(
            "SELECT j.*, c.name_fa AS city_name, p.name AS pack_name
             FROM collector_jobs j
             LEFT JOIN cities c ON c.id = j.city_id
             LEFT JOIN category_packs p ON p.id = j.pack_id
             WHERE j.id = :id",
            ['id' => $jobId]
        );

        Response::json([
            'ok' => true,
            'message' => sprintf(
                'Job #%d برای شهر «%s» با پرووایدر «%s» در صف (queued) قرار گرفت.',
                $jobId,
                $city['name_fa'],
                $provider
            ),
            'job' => $job,
        ]);
    }

    /**
     * تغییر وضعیت روشن/خاموش، اولویت یا بازنشانی توقف ۲۴ ساعته پرووایدر
     * POST /api/v1/collector/providers
     */
    public static function updateProvider(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);

        $provider = strtolower(trim((string) $request->input('provider', '')));
        $enabled = (int) $request->input('enabled', 1) === 1 ? 1 : 0;
        $priority = (int) $request->input('priority', 10);
        $clearPause = (bool) $request->input('clear_pause', false);

        if ($provider === '') {
            Response::json(['ok' => false, 'error' => 'نام پرووایدر الزامی است.'], 422);
        }

        if ($clearPause) {
            DB::query(
                "UPDATE provider_state SET enabled = :en, priority = :pr, paused_until = NULL, last_error = NULL WHERE provider = :p",
                ['en' => $enabled, 'pr' => $priority, 'p' => $provider]
            );
        } else {
            DB::query(
                "UPDATE provider_state SET enabled = :en, priority = :pr WHERE provider = :p",
                ['en' => $enabled, 'pr' => $priority, 'p' => $provider]
            );
        }

        Response::json([
            'ok' => true,
            'message' => "وضعیت پرووایدر «{$provider}» به‌روزرسانی شد.",
            'providers' => DB::fetchAll("SELECT * FROM provider_state ORDER BY priority ASC"),
        ]);
    }

    /**
     * چرخش یا ذخیره توکن امنیتی Bearer برای collector-remote
     * POST /api/v1/collector/token
     */
    public static function updateToken(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);

        $newToken = trim((string) $request->input('token', ''));
        if ($newToken === '') {
            $newToken = 'hhx-' . bin2hex(random_bytes(24));
        }

        if (strlen($newToken) < 16) {
            Response::json([
                'ok' => false,
                'error' => 'توکن Bearer باید حداقل ۱۶ کاراکتر باشد.',
            ], 422);
        }

        DB::query(
            "INSERT INTO settings (k, v, updated_at) VALUES ('collector_bearer_token', :v, NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()",
            ['v' => $newToken]
        );

        Response::json([
            'ok' => true,
            'collector_bearer_token' => $newToken,
            'message' => 'توکن ارتباطی collector-remote با موفقیت ذخیره شد.',
        ]);
    }

    // ========================================================================
    // اندپوینت‌های Pull مخصوص collector-remote روی کامپیوتر مدیر (Bearer Token)
    // ========================================================================

    /**
     * دریافت Job بعدی از صف توسط collector-remote
     * GET /api/v1/collector/jobs/next
     */
    public static function pullNextJob(Request $request): void
    {
        Auth::requireCollectorToken($request);

        // آزادسازی Jobهایی که بیش از ۱۵ دقیقه در حالت claimed/running گیر کرده‌اند
        DB::query(
            "UPDATE collector_jobs
             SET status = 'queued'
             WHERE status IN ('claimed', 'running')
               AND claimed_at < (NOW() - INTERVAL 15 MINUTE)
               AND attempts < 3"
        );

        // پیدا کردن اولین Job در صف که پرووایدر آن فعال است و در توقف ۲۴ ساعته نیست
        $job = DB::fetchOne(
            "SELECT j.*,
                    c.name_fa AS city_name,
                    c.slug AS city_slug,
                    c.lat AS city_lat,
                    c.lng AS city_lng,
                    c.bbox_json AS city_bbox_json,
                    c.publish_mode AS city_publish_mode
             FROM collector_jobs j
             INNER JOIN cities c ON c.id = j.city_id
             LEFT JOIN provider_state ps ON ps.provider = j.provider
             WHERE j.status = 'queued'
               AND (ps.enabled IS NULL OR ps.enabled = 1)
               AND (ps.paused_until IS NULL OR ps.paused_until < NOW())
             ORDER BY j.id ASC
             LIMIT 1"
        );

        if ($job === null) {
            Response::json([
                'ok' => true,
                'has_job' => false,
                'job' => null,
                'message' => 'هیچ Job آماده‌ای در صف وجود ندارد.',
            ]);
        }

        $jobId = (int) $job['id'];
        DB::query(
            "UPDATE collector_jobs
             SET status = 'running',
                 claimed_at = NOW(),
                 attempts = attempts + 1,
                 updated_at = NOW()
             WHERE id = :id",
            ['id' => $jobId]
        );

        $params = json_decode((string) ($job['params_json'] ?? '{}'), true);
        if (!is_array($params)) {
            $params = [];
        }

        // استخراج عبارت‌های جستجوی مخصوص این پرووایدر
        $prov = (string) $job['provider'];
        $queriesByProvider = $params['queries_by_provider'] ?? [];
        $queries = [];

        if (!empty($params['custom_query'])) {
            $queries[] = (string) $params['custom_query'];
        } elseif (!empty($queriesByProvider[$prov]) && is_array($queriesByProvider[$prov])) {
            $queries = $queriesByProvider[$prov];
        } else {
            $queries = ['کافه', 'رستوران'];
        }

        $photoPolicyRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'photo_policy' LIMIT 1");

        Response::json([
            'ok' => true,
            'has_job' => true,
            'job' => [
                'id' => $jobId,
                'city_id' => (int) $job['city_id'],
                'city_name_fa' => (string) $job['city_name'],
                'city_slug' => (string) $job['city_slug'],
                'lat' => (float) ($job['city_lat'] ?? 37.2808),
                'lng' => (float) ($job['city_lng'] ?? 49.5830),
                'bbox' => json_decode((string) ($job['city_bbox_json'] ?? '[37.19,49.48,37.36,49.68]'), true),
                'provider' => $prov,
                'category_id' => (int) ($params['category_id'] ?? 1),
                'queries' => $queries,
                'photo_policy' => (string) ($photoPolicyRow['v'] ?? 'none'),
            ],
        ]);
    }

    /**
     * دریافت نتایج جمع‌آوری‌شده از collector-remote (حداکثر ۲۰۰ آیتم و ۱ مگابایت)
     * یا دریافت گزارش خطای پرووایدر (۴۰۳ / ۴۲۹ / کپچا ← توقف ۲۴ ساعته و Fallback به پرووایدر بعدی)
     * POST /api/v1/collector/ingest
     */
    public static function ingest(Request $request): void
    {
        Auth::requireCollectorToken($request);

        $rawBody = $request->rawBody();
        if (strlen($rawBody) > self::MAX_INGEST_BYTES) {
            Response::json([
                'ok' => false,
                'error' => 'حجم محموله ارسالی بیش از حد مجاز (حداکثر ۱ مگابایت) است.',
            ], 413);
        }

        $payload = $request->json();
        $jobId = (int) ($payload['job_id'] ?? 0);
        if ($jobId <= 0) {
            Response::json(['ok' => false, 'error' => 'شناسه job_id الزامی است.'], 422);
        }

        $job = DB::fetchOne("SELECT * FROM collector_jobs WHERE id = :id", ['id' => $jobId]);
        if ($job === null) {
            Response::json(['ok' => false, 'error' => 'Job مورد نظر در سرور یافت نشد.'], 404);
        }

        $provider = (string) ($payload['provider'] ?? $job['provider']);
        $providerError = trim((string) ($payload['provider_error'] ?? ''));
        $httpCode = (int) ($payload['provider_http_code'] ?? 200);
        $isBlockedOrCaptcha = !empty($payload['is_blocked']) || in_array($httpCode, [403, 429], true);

        // ۱. مدیریت خطای پرووایدر، توقف ۲۴ ساعته روی 403/429/کپچا و Fallback خودکار به پرووایدر بعدی
        if ($providerError !== '' || $isBlockedOrCaptcha) {
            if ($isBlockedOrCaptcha) {
                DB::query(
                    "UPDATE provider_state
                     SET paused_until = (NOW() + INTERVAL 24 HOUR),
                         last_error = :err,
                         updated_at = NOW()
                     WHERE provider = :p",
                    ['err' => "HTTP {$httpCode}: {$providerError}", 'p' => $provider]
                );

                // پیدا کردن پرووایدر بعدی برای Fallback روی خطا
                $nextProvider = DB::fetchOne(
                    "SELECT provider FROM provider_state
                     WHERE enabled = 1
                       AND provider != :curr
                       AND (paused_until IS NULL OR paused_until < NOW())
                     ORDER BY priority ASC
                     LIMIT 1",
                    ['curr' => $provider]
                );

                if ($nextProvider !== null) {
                    $fallbackProv = (string) $nextProvider['provider'];
                    DB::query(
                        "UPDATE collector_jobs
                         SET provider = :next_p,
                             status = 'queued',
                             error = :err,
                             updated_at = NOW()
                         WHERE id = :id",
                        [
                            'next_p' => $fallbackProv,
                            'err' => "پرووایدر {$provider} به دلیل خطای {$httpCode}/کپچا به مدت ۲۴ ساعت متوقف شد و Job به {$fallbackProv} منتقل گردید.",
                            'id' => $jobId,
                        ]
                    );

                    Response::json([
                        'ok' => true,
                        'status' => 'fallback_queued',
                        'paused_provider' => $provider,
                        'fallback_provider' => $fallbackProv,
                        'message' => "پرووایدر {$provider} تا ۲۴ ساعت متوقف شد و Job به {$fallbackProv} منتقل گردید.",
                    ]);
                }
            }

            DB::query(
                "UPDATE collector_jobs SET status = 'failed', error = :err, updated_at = NOW() WHERE id = :id",
                ['err' => $providerError ?: "HTTP {$httpCode}", 'id' => $jobId]
            );

            Response::json([
                'ok' => true,
                'status' => 'failed',
                'message' => 'خطای اجرای Job ثبت شد.',
            ]);
        }

        // ۲. پردازش آیتم‌های دریافتی (حداکثر ۲۰۰ آیتم)
        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        if (count($items) > self::MAX_INGEST_ITEMS) {
            Response::json([
                'ok' => false,
                'error' => sprintf('حداکثر تعداد مجاز در هر درخواست ingest برابر %d آیتم است.', self::MAX_INGEST_ITEMS),
            ], 422);
        }

        $params = json_decode((string) ($job['params_json'] ?? '{}'), true) ?: [];
        $cityId = (int) $job['city_id'];
        $defaultCategoryId = (int) ($params['category_id'] ?? 1);

        $insertedCount = 0;
        $duplicateCount = 0;
        $updatedSourcesCount = 0;
        $errorCount = 0;
        $ingestedBusinesses = [];

        foreach ($items as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            try {
                $name = SlugService::normalizePersianText((string) ($rawItem['name'] ?? ''));
                if ($name === '') {
                    $errorCount++;
                    continue;
                }

                $sourceId = trim((string) ($rawItem['source_id'] ?? ''));
                if ($sourceId === '') {
                    $sourceId = md5($provider . ':' . $name . ':' . ($rawItem['lat'] ?? '') . ':' . ($rawItem['lng'] ?? ''));
                }

                $address = SlugService::normalizePersianText((string) ($rawItem['address'] ?? ''));
                $lat = isset($rawItem['lat']) && $rawItem['lat'] !== '' ? (float) $rawItem['lat'] : null;
                $lng = isset($rawItem['lng']) && $rawItem['lng'] !== '' ? (float) $rawItem['lng'] : null;
                $hours = SlugService::normalizePersianText((string) ($rawItem['hours'] ?? ''));
                $rating = isset($rawItem['rating']) && $rawItem['rating'] !== '' ? (float) $rawItem['rating'] : null;
                $reviewsCount = isset($rawItem['reviews_count']) ? max(0, (int) $rawItem['reviews_count']) : 0;
                $phones = isset($rawItem['phones']) && is_array($rawItem['phones']) ? $rawItem['phones'] : [];
                $normalizedPhones = [];
                foreach ($phones as $ph) {
                    $np = SlugService::normalizePhone((string) $ph);
                    if ($np !== '' && !in_array($np, $normalizedPhones, true)) {
                        $normalizedPhones[] = $np;
                    }
                }

                // بررسی اینکه آیا این source_id قبلاً برای همین پرووایدر ثبت شده است یا خیر
                $existingSource = DB::fetchOne(
                    "SELECT id, business_id FROM business_sources WHERE provider = :p AND source_id = :sid LIMIT 1",
                    ['p' => $provider, 'sid' => $sourceId]
                );

                if ($existingSource !== null) {
                    $bizId = (int) $existingSource['business_id'];
                    DB::query(
                        "UPDATE business_sources
                         SET raw = :raw, rating = :r, reviews_count = :rc, fetched_at = NOW()
                         WHERE id = :id",
                        [
                            'raw' => json_encode($rawItem, JSON_UNESCAPED_UNICODE),
                            'r' => $rating,
                            'rc' => $reviewsCount,
                            'id' => (int) $existingSource['id'],
                        ]
                    );
                    DB::query("UPDATE businesses SET last_collected_at = NOW() WHERE id = :id", ['id' => $bizId]);
                    $duplicateCount++;
                    $ingestedBusinesses[] = ['business_id' => $bizId, 'source_id' => $sourceId, 'is_new' => false];
                    continue;
                }

                // بررسی تکراری بودن بر اساس نام نرمال‌شده در همان شهر یا تطابق شماره تلفن
                $normName = preg_replace('/\s+/u', '', $name) ?: $name;
                $matchedBiz = DB::fetchOne(
                    "SELECT id, lat, lng, address FROM businesses
                     WHERE city_id = :cid AND normalized_name = :norm
                     LIMIT 1",
                    ['cid' => $cityId, 'norm' => $normName]
                );

                if ($matchedBiz === null && !empty($normalizedPhones)) {
                    $firstPhone = $normalizedPhones[0];
                    $contactMatch = DB::fetchOne(
                        "SELECT b.id, b.lat, b.lng, b.address
                         FROM business_contacts bc
                         INNER JOIN businesses b ON b.id = bc.business_id
                         WHERE b.city_id = :cid AND bc.value = :val
                         LIMIT 1",
                        ['cid' => $cityId, 'val' => $firstPhone]
                    );
                    if ($contactMatch !== null) {
                        $matchedBiz = $contactMatch;
                    }
                }

                // تشخیص خودکار محله از آدرس در صورت وجود در جدول neighborhoods شهر
                $neighborhoodId = self::matchNeighborhoodFromAddress($cityId, $address);

                if ($matchedBiz !== null) {
                    $bizId = (int) $matchedBiz['id'];
                    DB::query(
                        "INSERT INTO business_sources (business_id, provider, source_id, raw, rating, reviews_count, fetched_at)
                         VALUES (:bid, :p, :sid, :raw, :r, :rc, NOW())",
                        [
                            'bid' => $bizId,
                            'p' => $provider,
                            'sid' => $sourceId,
                            'raw' => json_encode($rawItem, JSON_UNESCAPED_UNICODE),
                            'r' => $rating,
                            'rc' => $reviewsCount,
                        ]
                    );
                    DB::query(
                        "UPDATE businesses SET confidence = 'high', last_collected_at = NOW() WHERE id = :id",
                        ['id' => $bizId]
                    );
                    $updatedSourcesCount++;
                    $duplicateCount++;
                } else {
                    $baseSlug = SlugService::suggestSlug($name, 'biz');
                    $uniqueSlug = SlugService::ensureUniqueSlug('businesses', $baseSlug);

                    // محاسبه امتیاز اولیه بیزی (در مرحله S3 پایپ‌لاین کامل و قوانین چندمنبعی اجرا می‌شود)
                    $m = 20.0;
                    $c = 4.10;
                    $v = (float) $reviewsCount;
                    $r = $rating !== null && $rating > 0 ? $rating : $c;
                    $bayesScore = (($v / ($v + $m)) * $r) + (($m / ($v + $m)) * $c);
                    if (!empty($normalizedPhones)) {
                        $bayesScore += 0.15;
                    }
                    if ($lat !== null && $lng !== null) {
                        $bayesScore += 0.10;
                    }
                    $bayesScore = min(5.0, round($bayesScore, 3));

                    $hasValidPhone = !empty($normalizedPhones);
                    $hasCoords = $lat !== null && $lng !== null && $address !== '';
                    $status = ($hasValidPhone && $hasCoords && $bayesScore >= 3.85) ? 'published' : 'pending_review';
                    $publishReason = $status === 'published' ? 'auto_rules_s2' : 'missing_phone_or_low_score';
                    $tier = $bayesScore >= 4.30 ? 'A' : ($bayesScore >= 3.95 ? 'B' : 'C');

                    DB::query(
                        "INSERT INTO businesses
                            (name, normalized_name, slug, category_id, city_id, neighborhood_id, address, lat, lng, hours, status, tier, internal_score, confidence, publish_reason, last_collected_at, created_at, updated_at)
                         VALUES
                            (:name, :norm, :slug, :cat_id, :city_id, :nid, :addr, :lat, :lng, :hours, :status, :tier, :score, :conf, :reason, NOW(), NOW(), NOW())",
                        [
                            'name' => $name,
                            'norm' => $normName,
                            'slug' => $uniqueSlug,
                            'cat_id' => $defaultCategoryId,
                            'city_id' => $cityId,
                            'nid' => $neighborhoodId,
                            'addr' => $address !== '' ? $address : null,
                            'lat' => $lat,
                            'lng' => $lng,
                            'hours' => $hours !== '' ? $hours : null,
                            'status' => $status,
                            'tier' => $tier,
                            'score' => $bayesScore,
                            'conf' => $hasValidPhone ? 'medium' : 'low',
                            'reason' => $publishReason,
                        ]
                    );

                    $bizId = DB::lastInsertId();

                    DB::query(
                        "INSERT INTO business_sources (business_id, provider, source_id, raw, rating, reviews_count, fetched_at)
                         VALUES (:bid, :p, :sid, :raw, :r, :rc, NOW())",
                        [
                            'bid' => $bizId,
                            'p' => $provider,
                            'sid' => $sourceId,
                            'raw' => json_encode($rawItem, JSON_UNESCAPED_UNICODE),
                            'r' => $rating,
                            'rc' => $reviewsCount,
                        ]
                    );

                    foreach ($normalizedPhones as $idx => $normPhone) {
                        $phoneType = str_starts_with($normPhone, '+989') ? 'mobile' : 'phone';
                        DB::query(
                            "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                             VALUES (:bid, :type, :val, :prim, :src, 'medium', NOW())",
                            [
                                'bid' => $bizId,
                                'type' => $phoneType,
                                'val' => $normPhone,
                                'prim' => $idx === 0 ? 1 : 0,
                                'src' => $provider,
                            ]
                        );
                    }

                    if ($status === 'pending_review') {
                        DB::query(
                            "INSERT INTO review_queue (kind, target_id, reason_code, payload_json, status, created_at)
                             VALUES ('business', :tid, :rc, :payload, 'pending', NOW())",
                            [
                                'tid' => $bizId,
                                'rc' => $publishReason,
                                'payload' => json_encode(['name' => $name, 'provider' => $provider], JSON_UNESCAPED_UNICODE),
                            ]
                        );
                    }

                    $insertedCount++;
                }

                $ingestedBusinesses[] = [
                    'business_id' => $bizId,
                    'source_id' => $sourceId,
                    'is_new' => $matchedBiz === null,
                ];
            } catch (Throwable $e) {
                $errorCount++;
            }
        }

        $isFinalBatch = !isset($payload['is_final']) || (bool) $payload['is_final'] === true;

        $prevResult = json_decode((string) ($job['result_json'] ?? '{}'), true);
        if (!is_array($prevResult)) {
            $prevResult = ['found' => 0, 'inserted' => 0, 'duplicates' => 0, 'errors' => 0];
        }

        $newResult = [
            'found' => (int) ($prevResult['found'] ?? 0) + count($items),
            'inserted' => (int) ($prevResult['inserted'] ?? 0) + $insertedCount,
            'duplicates' => (int) ($prevResult['duplicates'] ?? 0) + $duplicateCount,
            'updated_sources' => (int) ($prevResult['updated_sources'] ?? 0) + $updatedSourcesCount,
            'errors' => (int) ($prevResult['errors'] ?? 0) + $errorCount,
            'finished_at' => date('Y-m-d H:i:s'),
        ];

        DB::query(
            "UPDATE collector_jobs
             SET status = :st,
                 result_json = :res,
                 error = NULL,
                 updated_at = NOW()
             WHERE id = :id",
            [
                'st' => $isFinalBatch ? 'done' : 'running',
                'res' => json_encode($newResult, JSON_UNESCAPED_UNICODE),
                'id' => $jobId,
            ]
        );

        Response::json([
            'ok' => true,
            'job_id' => $jobId,
            'status' => $isFinalBatch ? 'done' : 'running',
            'summary' => $newResult,
            'ingested_businesses' => $ingestedBusinesses,
        ]);
    }

    /**
     * آپلود عکس از سمت collector-remote (اصلاحیه ۳: هاست هیچ عکسی را مستقیم دانلود نمی‌کند)
     * POST /api/v1/collector/photo (Bearer token، multipart، حداکثر ۲ مگابایت)
     */
    public static function uploadCollectorPhoto(Request $request): void
    {
        Auth::requireCollectorToken($request);

        $photoPolicyRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'photo_policy' LIMIT 1");
        $policy = (string) ($photoPolicyRow['v'] ?? 'none');

        if ($policy !== 'store') {
            Response::json([
                'ok' => false,
                'error' => 'سیاست عکس روی سرور در حالت none قرار دارد و دریافت عکس پرووایدر غیرفعال است.',
            ], 403);
        }

        $businessId = (int) ($_POST['business_id'] ?? 0);
        $sourceUrl = trim((string) ($_POST['source_url'] ?? ''));
        $attribution = trim((string) ($_POST['attribution'] ?? ''));

        if ($businessId <= 0 || empty($_FILES['photo'])) {
            Response::json([
                'ok' => false,
                'error' => 'پارامترهای business_id و فایل photo الزامی هستند.',
            ], 422);
        }

        if (((int) ($_FILES['photo']['size'] ?? 0)) > 2 * 1024 * 1024) {
            Response::json([
                'ok' => false,
                'error' => 'حجم عکس ارسالی از collector-remote نباید بیشتر از ۲ مگابایت باشد.',
            ], 413);
        }

        try {
            $media = MediaService::handleUpload($_FILES['photo'], $attribution ?: 'تصویر کسب‌وکار');

            DB::query(
                "INSERT INTO business_photos (business_id, media_id, source, source_url, attribution, status, sort, created_at)
                 VALUES (:bid, :mid, 'provider', :url, :attr, 'approved', 1, NOW())",
                [
                    'bid' => $businessId,
                    'mid' => (int) $media['id'],
                    'url' => $sourceUrl !== '' ? $sourceUrl : null,
                    'attr' => $attribution !== '' ? $attribution : null,
                ]
            );

            DB::query(
                "UPDATE businesses SET cover_media_id = COALESCE(cover_media_id, :mid) WHERE id = :bid",
                ['mid' => (int) $media['id'], 'bid' => $businessId]
            );

            Response::json([
                'ok' => true,
                'media_id' => (int) $media['id'],
                'message' => 'تصویر با موفقیت توسط GD به WebP تبدیل و به کسب‌وکار متصل شد.',
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * تطبیق یا ساخت محله از روی رشته آدرس جمع‌آوری‌شده در همان شهر
     */
    private static function matchNeighborhoodFromAddress(int $cityId, string $address): ?int
    {
        if ($address === '') {
            return null;
        }

        $existing = DB::fetchAll(
            "SELECT id, name_fa FROM neighborhoods WHERE city_id = :cid",
            ['cid' => $cityId]
        );

        foreach ($existing as $n) {
            $nName = (string) $n['name_fa'];
            if ($nName !== '' && mb_strpos($address, $nName) !== false) {
                return (int) $n['id'];
            }
        }

        // استخراج الگوهای رایج محله در آدرس فارسی (مانند «محله ...» یا «بلوار ...»)
        if (preg_match('/(?:محله|میدان|بلوار)\s+([آ-ی]{3,20})/u', $address, $m)) {
            $detectedName = SlugService::normalizePersianText($m[1]);
            if ($detectedName !== '' && mb_strlen($detectedName) >= 3) {
                $slug = SlugService::suggestSlug($detectedName, 'nh');
                DB::query(
                    "INSERT IGNORE INTO neighborhoods (city_id, name_fa, slug, created_at)
                     VALUES (:cid, :name, :slug, NOW())",
                    ['cid' => $cityId, 'name' => $detectedName, 'slug' => $slug]
                );
                $row = DB::fetchOne(
                    "SELECT id FROM neighborhoods WHERE city_id = :cid AND slug = :slug LIMIT 1",
                    ['cid' => $cityId, 'slug' => $slug]
                );
                if ($row !== null) {
                    return (int) $row['id'];
                }
            }
        }

        return null;
    }
}
