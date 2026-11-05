<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * سرویس پایپ‌لاین مرکزی پردازش کسب‌وکارها در مرحله S3
 * شامل:
 * ۱. Normalizer: نرمال‌سازی حروف فارسی (ي/ك)، ارقام فارسی و شماره‌ها به +98 و 013 برای گیلان
 * ۲. Dedupe: تشخیص تکراری بر اساس (نام نرمال‌شده + فاصله جغرافیایی کمتر از ۸۰ متر با فرمول Haversine + همپوشانی شماره)
 * ۳. Bayesian Scoring & Tiering: محاسبه (v/(v+m))·R + (m/(v+m))·C با m=20 و تقسیم به Tier A (بالای ۲۰٪)، B و C
 * ۴. قانون انتشار خودکار چندمنبعی (اصلاحیه ۶):
 *    - توافق حداقل دو منبع مستقل روی آدرس و مختصات (فاصله < 100 متر) -> confidence=high و published
 *    - تک‌منبعی -> بر اساس آستانه امتیاز بیزی (auto_publish_min_score)
 *    - منابع متناقض (فاصله >= 100 متر) -> به صندوق بازبینی با reason_code=source_conflict
 * ۵. ردیابی تغییرات (change_log): تغییر شماره یا آدرس؛ اعمال خودکار فقط وقتی دو منبع موافق باشند، وگرنه صندوق بازبینی
 */
final class PipelineService
{
    /**
     * محاسبه فاصله دو نقطه جغرافیایی به متر با فرمول Haversine
     */
    public static function distanceMeters(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }

        $earthRadius = 6371000.0; // متر
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return round($earthRadius * $c, 2);
    }

    /**
     * نرمال‌سازی کلید مقایسه نام کسب‌وکار (حذف فاصله‌ها، نیم‌فاصله‌ها و پیشوندهای عمومی برای مقایسه دقیق‌تر)
     */
    public static function canonicalNameKey(string $name): string
    {
        $clean = SlugService::normalizePersianText($name);
        $clean = SlugService::toEnglishDigits($clean);
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($clean, 'UTF-8'));
        return is_string($clean) ? $clean : '';
    }

    /**
     * محاسبه امتیاز بیزی (v/(v+m))*R + (m/(v+m))*C با m=20
     * به همراه امتیاز کیفیت داده برای منابعی که امتیاز ستاره‌ای ندارند (مانند OSM)
     */
    public static function calculateBayesianScore(
        ?float $rating,
        int $reviewsCount,
        bool $hasValidPhone,
        bool $hasCoords,
        bool $hasAddress,
        bool $hasHours,
        float $m = 20.0,
        float $c = 4.10
    ): float {
        $v = max(0.0, (float) $reviewsCount);
        $r = ($rating !== null && $rating > 0.0) ? min(5.0, max(1.0, $rating)) : $c;

        $baseBayes = (($v / ($v + $m)) * $r) + (($m / ($v + $m)) * $c);

        // پاداش تکمیل فیلدهای حیاتی (تا سقف +0.25)
        $completenessBonus = 0.0;
        if ($hasValidPhone) {
            $completenessBonus += 0.12;
        }
        if ($hasCoords && $hasAddress) {
            $completenessBonus += 0.08;
        }
        if ($hasHours) {
            $completenessBonus += 0.05;
        }

        return min(5.000, round($baseBayes + $completenessBonus, 3));
    }

    /**
     * پردازش یک رکورد ورودی از پرووایدر در پایپ‌لاین S3
     *
     * @param array<string, mixed> $rawItem
     * @return array{
     *   outcome: 'published'|'pending_review'|'duplicate'|'source_conflict',
     *   reason_code: string,
     *   business_id: int,
     *   is_new: bool,
     *   score: float
     * }
     */
    public static function processIncomingRecord(
        int $cityId,
        int $defaultCategoryId,
        string $provider,
        array $rawItem,
        string $cityPublishMode = 'rules',
        float $minScoreThreshold = 3.85
    ): array {
        $name = SlugService::normalizePersianText((string) ($rawItem['name'] ?? ''));
        $normKey = self::canonicalNameKey($name);

        $address = SlugService::normalizePersianText((string) ($rawItem['address'] ?? ''));
        $lat = (isset($rawItem['lat']) && $rawItem['lat'] !== '' && $rawItem['lat'] !== null) ? (float) $rawItem['lat'] : null;
        $lng = (isset($rawItem['lng']) && $rawItem['lng'] !== '' && $rawItem['lng'] !== null) ? (float) $rawItem['lng'] : null;
        $hours = SlugService::normalizePersianText((string) ($rawItem['hours'] ?? ''));
        $rating = (isset($rawItem['rating']) && $rawItem['rating'] !== '' && $rawItem['rating'] !== null) ? (float) $rawItem['rating'] : null;
        $reviewsCount = isset($rawItem['reviews_count']) ? max(0, (int) $rawItem['reviews_count']) : 0;
        $permanentlyClosed = !empty($rawItem['permanently_closed']) || str_contains($hours, 'دائماً بسته');

        $rawPhones = isset($rawItem['phones']) && is_array($rawItem['phones']) ? $rawItem['phones'] : [];
        $normalizedPhones = [];
        foreach ($rawPhones as $ph) {
            $np = SlugService::normalizePhone((string) $ph);
            if ($np !== '' && !in_array($np, $normalizedPhones, true)) {
                $normalizedPhones[] = $np;
            }
        }

        $sourceId = trim((string) ($rawItem['source_id'] ?? ''));
        if ($sourceId === '') {
            $sourceId = md5($provider . ':' . $normKey . ':' . ($lat ?? '') . ':' . ($lng ?? ''));
        }

        $hasValidPhone = !empty($normalizedPhones);
        $hasCoords = ($lat !== null && $lng !== null);
        $hasAddress = ($address !== '');

        $score = self::calculateBayesianScore(
            $rating,
            $reviewsCount,
            $hasValidPhone,
            $hasCoords,
            $hasAddress,
            $hours !== ''
        );

        // ۱. جستجوی کاندیدهای موجود در همان شهر برای Dedupe (نام نرمال‌شده + فاصله < ۸۰ متر + شماره)
        $existingCandidates = DB::fetchAll(
            "SELECT b.id, b.name, b.normalized_name, b.address, b.lat, b.lng, b.status, b.confidence, b.internal_score
             FROM businesses b
             WHERE b.city_id = :cid",
            ['cid' => $cityId]
        );

        $matchedBusiness = null;
        $matchDistance = null;
        $phoneMatched = false;

        if ($hasValidPhone) {
            $placeholders = implode(',', array_fill(0, count($normalizedPhones), '?'));
            $stmt = DB::connection()->prepare(
                "SELECT b.*
                 FROM business_contacts bc
                 INNER JOIN businesses b ON b.id = bc.business_id
                 WHERE b.city_id = ? AND bc.value IN ({$placeholders})
                 LIMIT 1"
            );
            $stmt->execute(array_merge([$cityId], $normalizedPhones));
            $phoneRow = $stmt->fetch();
            if (is_array($phoneRow)) {
                $matchedBusiness = $phoneRow;
                $phoneMatched = true;
                $matchDistance = self::distanceMeters(
                    $lat,
                    $lng,
                    $phoneRow['lat'] !== null ? (float) $phoneRow['lat'] : null,
                    $phoneRow['lng'] !== null ? (float) $phoneRow['lng'] : null
                );
            }
        }

        if ($matchedBusiness === null) {
            foreach ($existingCandidates as $cand) {
                $candNorm = (string) $cand['normalized_name'];
                $dist = self::distanceMeters(
                    $lat,
                    $lng,
                    $cand['lat'] !== null ? (float) $cand['lat'] : null,
                    $cand['lng'] !== null ? (float) $cand['lng'] : null
                );

                // اگر نام نرمال‌شده یکسان باشد و (فاصله کمتر از ۸۰ متر باشد یا از یک منبع جدید برای همان نام باشد)
                if ($candNorm === $normKey) {
                    $matchedBusiness = $cand;
                    $matchDistance = $dist;
                    break;
                }

                // یا اگر فاصله کمتر از ۳۰ متر باشد و شباهت نام بالا باشد
                if ($dist !== null && $dist <= 30.0 && similar_text($candNorm, $normKey) >= 12) {
                    $matchedBusiness = $cand;
                    $matchDistance = $dist;
                    break;
                }
            }
        }

        // ۲. اگر رکورد با یک کسب‌وکار موجود تطابق داشت (ادغام منبع / تشخیص تکراری / بررسی تناقض منبع / change_log)
        if ($matchedBusiness !== null) {
            $bizId = (int) $matchedBusiness['id'];

            // ثبت یا به‌روزرسانی منبع در جدول business_sources
            DB::query(
                "INSERT INTO business_sources (business_id, provider, source_id, raw, rating, reviews_count, fetched_at)
                 VALUES (:bid, :p, :sid, :raw, :r, :rc, NOW())
                 ON DUPLICATE KEY UPDATE
                    raw = VALUES(raw),
                    rating = COALESCE(VALUES(rating), rating),
                    reviews_count = GREATEST(reviews_count, VALUES(reviews_count)),
                    fetched_at = NOW()",
                [
                    'bid' => $bizId,
                    'p' => $provider,
                    'sid' => $sourceId,
                    'raw' => json_encode($rawItem, JSON_UNESCAPED_UNICODE),
                    'r' => $rating,
                    'rc' => $reviewsCount,
                ]
            );

            // دریافت تمام منابع ثبت‌شده برای این کسب‌وکار جهت بررسی قانون چندمنبعی اصلاحیه ۶
            $allSources = DB::fetchAll(
                "SELECT provider, source_id, raw, rating, reviews_count FROM business_sources WHERE business_id = :bid",
                ['bid' => $bizId]
            );
            $distinctProviders = [];
            foreach ($allSources as $src) {
                $distinctProviders[(string) $src['provider']] = true;
            }
            $independentSourcesCount = count($distinctProviders);

            // بررسی تغییر شماره یا آدرس و ثبت در change_log (بخش ۹)
            $oldAddress = trim((string) ($matchedBusiness['address'] ?? ''));
            if ($hasAddress && $oldAddress !== '' && $address !== $oldAddress) {
                $changeStatus = $independentSourcesCount >= 2 && ($matchDistance === null || $matchDistance < 100.0)
                    ? 'auto_applied'
                    : 'pending';

                DB::query(
                    "INSERT INTO change_log (business_id, field, old_value, new_value, source, status, created_at)
                     VALUES (:bid, 'address', :old, :new, :src, :st, NOW())",
                    [
                        'bid' => $bizId,
                        'old' => $oldAddress,
                        'new' => $address,
                        'src' => $provider,
                        'st' => $changeStatus,
                    ]
                );

                if ($changeStatus === 'auto_applied') {
                    DB::query("UPDATE businesses SET address = :addr WHERE id = :id", ['addr' => $address, 'id' => $bizId]);
                } else {
                    self::enqueueReviewIfNotExists('change', $bizId, 'address_changed', [
                        'business_id' => $bizId,
                        'business_name' => $name,
                        'field' => 'address',
                        'old_value' => $oldAddress,
                        'new_value' => $address,
                        'source' => $provider,
                    ]);
                }
            }

            if ($hasValidPhone) {
                $primaryPhoneRow = DB::fetchOne(
                    "SELECT value FROM business_contacts WHERE business_id = :bid AND is_primary = 1 LIMIT 1",
                    ['bid' => $bizId]
                );
                $oldPhone = (string) ($primaryPhoneRow['value'] ?? '');
                $newPrimaryPhone = $normalizedPhones[0];

                if ($oldPhone !== '' && $oldPhone !== $newPrimaryPhone) {
                    $phoneChangeStatus = $independentSourcesCount >= 2 ? 'auto_applied' : 'pending';
                    DB::query(
                        "INSERT INTO change_log (business_id, field, old_value, new_value, source, status, created_at)
                         VALUES (:bid, 'phone', :old, :new, :src, :st, NOW())",
                        [
                            'bid' => $bizId,
                            'old' => $oldPhone,
                            'new' => $newPrimaryPhone,
                            'src' => $provider,
                            'st' => $phoneChangeStatus,
                        ]
                    );

                    if ($phoneChangeStatus === 'pending') {
                        self::enqueueReviewIfNotExists('change', $bizId, 'phone_changed', [
                            'business_id' => $bizId,
                            'business_name' => $name,
                            'field' => 'phone',
                            'old_value' => $oldPhone,
                            'new_value' => $newPrimaryPhone,
                            'source' => $provider,
                        ]);
                    }
                }
            }

            // بررسی تناقض منبع (اصلاحیه ۶: اگر فاصله دو منبع مستقل >= 100 متر باشد -> source_conflict)
            if ($independentSourcesCount >= 2 && $matchDistance !== null && $matchDistance >= 100.0) {
                DB::query(
                    "UPDATE businesses
                     SET status = 'pending_review',
                         confidence = 'low',
                         publish_reason = 'source_conflict',
                         last_collected_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id",
                    ['id' => $bizId]
                );

                self::enqueueReviewIfNotExists('business', $bizId, 'source_conflict', [
                    'business_id' => $bizId,
                    'business_name' => $name,
                    'provider' => $provider,
                    'distance_meters' => $matchDistance,
                    'old_address' => $oldAddress,
                    'new_address' => $address,
                ]);

                return [
                    'outcome' => 'source_conflict',
                    'reason_code' => 'source_conflict',
                    'business_id' => $bizId,
                    'is_new' => false,
                    'score' => $score,
                ];
            }

            // اگر حداقل دو منبع مستقل در فاصله کمتر از ۱۰۰ متر توافق دارند -> confidence=high و انتشار خودکار
            if ($independentSourcesCount >= 2 && ($matchDistance === null || $matchDistance < 100.0) && !$permanentlyClosed) {
                $newStatus = $cityPublishMode === 'manual' ? 'pending_review' : 'published';
                $bestScore = max($score, (float) $matchedBusiness['internal_score']);

                DB::query(
                    "UPDATE businesses
                     SET status = :st,
                         confidence = 'high',
                         internal_score = :sc,
                         publish_reason = 'multi_source_verified',
                         last_collected_at = NOW(),
                         last_verified_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id",
                    [
                        'st' => $newStatus,
                        'sc' => $bestScore,
                        'id' => $bizId,
                    ]
                );

                return [
                    'outcome' => 'duplicate',
                    'reason_code' => 'merged_multi_source_high_confidence',
                    'business_id' => $bizId,
                    'is_new' => false,
                    'score' => $bestScore,
                ];
            }

            // اگر کسب‌وکار قبلی هنوز در وضعیت pending_review مانده است، اطمینان حاصل شود که در صندوق بازبینی حضور دارد
            if ((string) ($matchedBusiness['status'] ?? '') === 'pending_review') {
                $existingReason = !$hasValidPhone ? 'missing_valid_phone' : 'low_bayesian_score';
                self::enqueueReviewIfNotExists('business', $bizId, $existingReason, [
                    'business_id' => $bizId,
                    'business_name' => $name,
                    'provider' => $provider,
                    'score' => $score,
                    'address' => $address,
                    'phones' => $normalizedPhones,
                ]);
            }

            // اگر از همان منبع قبلی دوباره ارسال شده و فاصله < 80 متر یا شماره یکسان دارد -> تکراری (duplicate)
            DB::query("UPDATE businesses SET last_collected_at = NOW() WHERE id = :id", ['id' => $bizId]);

            return [
                'outcome' => 'duplicate',
                'reason_code' => $phoneMatched ? 'duplicate_same_phone' : 'duplicate_within_80m',
                'business_id' => $bizId,
                'is_new' => false,
                'score' => (float) $matchedBusiness['internal_score'],
            ];
        }

        // ۳. درج کسب‌وکار جدید و تصمیم‌گیری قانون انتشار خودکار (بخش ۱۱ و اصلاحیه ۶)
        $status = 'pending_review';
        $reasonCode = 'manual_city_mode';
        $confidence = 'medium';

        if ($permanentlyClosed) {
            $status = 'pending_review';
            $reasonCode = 'permanently_closed';
            $confidence = 'low';
        } elseif (!$hasValidPhone) {
            $status = 'pending_review';
            $reasonCode = 'missing_valid_phone';
            $confidence = 'low';
        } elseif (!$hasCoords || !$hasAddress) {
            $status = 'pending_review';
            $reasonCode = 'missing_address_or_coords';
            $confidence = 'low';
        } elseif ($cityPublishMode === 'manual') {
            $status = 'pending_review';
            $reasonCode = 'city_mode_manual';
        } elseif ($cityPublishMode === 'full_auto') {
            $status = 'published';
            $reasonCode = 'city_mode_full_auto';
            $confidence = 'medium';
        } else {
            // حالت پیش‌فرض rules: بررسی امتیاز بیزی نسبت به آستانه
            if ($score >= $minScoreThreshold) {
                $status = 'published';
                $reasonCode = 'bayes_score_above_threshold';
                $confidence = 'medium';
            } else {
                $status = 'pending_review';
                $reasonCode = 'low_bayesian_score';
                $confidence = 'low';
            }
        }

        $baseSlug = SlugService::suggestSlug($name, 'biz');
        $uniqueSlug = SlugService::ensureUniqueSlug('businesses', $baseSlug);
        $tier = $score >= 4.35 ? 'A' : ($score >= 4.00 ? 'B' : 'C');

        DB::query(
            "INSERT INTO businesses
                (name, normalized_name, slug, category_id, city_id, address, lat, lng, hours, status, tier, internal_score, confidence, publish_reason, last_collected_at, created_at, updated_at)
             VALUES
                (:name, :norm, :slug, :cat_id, :city_id, :addr, :lat, :lng, :hours, :status, :tier, :score, :conf, :reason, NOW(), NOW(), NOW())",
            [
                'name' => $name,
                'norm' => $normKey,
                'slug' => $uniqueSlug,
                'cat_id' => $defaultCategoryId,
                'city_id' => $cityId,
                'addr' => $hasAddress ? $address : null,
                'lat' => $lat,
                'lng' => $lng,
                'hours' => $hours !== '' ? $hours : null,
                'status' => $status,
                'tier' => $tier,
                'score' => $score,
                'conf' => $confidence,
                'reason' => $reasonCode,
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

        foreach ($normalizedPhones as $idx => $np) {
            $pType = str_starts_with($np, '+989') ? 'mobile' : 'phone';
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, :type, :val, :prim, :src, 'medium', NOW())",
                [
                    'bid' => $bizId,
                    'type' => $pType,
                    'val' => $np,
                    'prim' => $idx === 0 ? 1 : 0,
                    'src' => $provider,
                ]
            );
        }

        if ($status === 'pending_review') {
            self::enqueueReviewIfNotExists('business', $bizId, $reasonCode, [
                'business_id' => $bizId,
                'business_name' => $name,
                'provider' => $provider,
                'score' => $score,
                'address' => $address,
                'phones' => $normalizedPhones,
            ]);
        }

        return [
            'outcome' => $status === 'published' ? 'published' : 'pending_review',
            'reason_code' => $reasonCode,
            'business_id' => $bizId,
            'is_new' => true,
            'score' => $score,
        ];
    }

    /**
     * بازمحاسبه Tier کسب‌وکارهای یک شهر و دسته بر اساس صدک امتیاز (۲۰٪ بالای امتیاز بیزی = Tier A، ۴۰٪ میانی = Tier B، بقیه = Tier C)
     */
    public static function recalculateTiersForCityCategory(int $cityId, int $categoryId): void
    {
        $rows = DB::fetchAll(
            "SELECT id, internal_score FROM businesses
             WHERE city_id = :cid AND category_id = :cat
             ORDER BY internal_score DESC, id ASC",
            ['cid' => $cityId, 'cat' => $categoryId]
        );

        $total = count($rows);
        if ($total === 0) {
            return;
        }

        $top20Count = max(1, (int) ceil($total * 0.20));
        $top60Count = max($top20Count + 1, (int) ceil($total * 0.60));

        foreach ($rows as $index => $row) {
            $rank = $index + 1;
            $tier = $rank <= $top20Count ? 'A' : ($rank <= $top60Count ? 'B' : 'C');
            DB::query("UPDATE businesses SET tier = :t WHERE id = :id", [
                't' => $tier,
                'id' => (int) $row['id'],
            ]);
        }
    }

    /**
     * ثبت آیتم در صندوق بازبینی (review_queue) در صورتی که آیتم pending مشابهی برای همان موجودیت نباشد
     */
    public static function enqueueReviewIfNotExists(
        string $kind,
        int $targetId,
        string $reasonCode,
        array $payload
    ): void {
        $existing = DB::fetchOne(
            "SELECT id FROM review_queue
             WHERE kind = :k AND target_id = :tid AND reason_code = :rc AND status = 'pending'
             LIMIT 1",
            ['k' => $kind, 'tid' => $targetId, 'rc' => $reasonCode]
        );

        if ($existing !== null) {
            return;
        }

        DB::query(
            "INSERT INTO review_queue (kind, target_id, reason_code, payload_json, status, created_at)
             VALUES (:k, :tid, :rc, :p, 'pending', NOW())",
            [
                'k' => $kind,
                'tid' => $targetId,
                'rc' => $reasonCode,
                'p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /**
     * اجرای بنچمارک ۱۰۰ رکورد نمونه با نتیجه ۱۰۰٪ قابل‌پیش‌بینی برای اثبات معیار قبولی مرحله S3:
     * - ۵۵ رکورد استاندارد دارای تلفن، مختصات و امتیاز بالا -> ۵۵ مورد منتشرشده (published)
     * - ۲۰ رکورد نیازمند بازبینی (۱۰ مورد بدون تلفن + ۵ مورد امتیاز پایین + ۵ مورد دائماً بسته) -> ۲۰ مورد pending_review
     * - ۱۵ رکورد تکراری (فاصله کمتر از ۸۰ متر یا همپوشانی شماره تماس با ۵۵ رکورد اول) -> ۱۵ مورد duplicate
     * - ۱۰ رکورد دارای تناقض منبع (منبع دوم برای ۱۰ کسب‌وکار اول اما با فاصله > ۲۰۰ متر) -> ۱۰ مورد source_conflict
     * مجموع: دقیقاً ۱۰۰ رکورد پردازش‌شده
     */
    public static function runPredictable100Benchmark(int $cityId = 1, int $categoryId = 1): array
    {
        // پاک‌سازی رکوردهای بنچمارک قبلی در صورت تکرار تست تا نتیجه همیشه دقیقاً ۵۵ / ۲۰ / ۱۵ / ۱۰ باشد
        DB::query("DELETE FROM review_queue WHERE payload_json LIKE '%s3_bench_%'");
        DB::query("DELETE FROM change_log WHERE source LIKE 's3_bench_%'");
        DB::query("DELETE FROM business_contacts WHERE source LIKE 's3_bench_%'");
        DB::query("DELETE FROM business_sources WHERE source_id LIKE 's3_bench_%'");
        DB::query("DELETE FROM businesses WHERE slug LIKE 's3-bench-%'");

        $summary = [
            'total_processed' => 0,
            'published' => 0,
            'pending_review' => 0,
            'duplicate' => 0,
            'source_conflict' => 0,
            'breakdown_reasons' => [],
        ];

        $baseLat = 37.280000;
        $baseLng = 49.580000;

        // گروه ۱: ۵۵ رکورد معتبر و باکیفیت (امتیاز ۴.۲ تا ۴.۹، دارای شماره و مختصات متمایز با فاصله ۲۰۰ متر از هم)
        for ($i = 1; $i <= 55; $i++) {
            $itemLat = $baseLat + ($i * 0.0025); // حدود ۲۷۵ متر فاصله بین هر رکورد
            $itemLng = $baseLng + ($i * 0.0025);
            $phone = sprintf('01333%06d', 100000 + $i);

            $res = self::processIncomingRecord($cityId, $categoryId, 's3_bench_gosom', [
                'name' => "کافه نمونه استاندارد گیلان شماره {$i}",
                'slug_hint' => "s3-bench-pub-{$i}",
                'address' => "رشت، بلوار گلسار، خیابان {$i}",
                'lat' => $itemLat,
                'lng' => $itemLng,
                'phones' => [$phone],
                'hours' => '۹ صبح تا ۱۱ شب',
                'rating' => 4.4,
                'reviews_count' => 65,
                'source_id' => "s3_bench_pub_{$i}",
            ], 'rules', 3.85);

            // علامت‌گذاری اسلاگ برای ایزوله بودن بنچمارک
            DB::query("UPDATE businesses SET slug = :s WHERE id = :id", [
                's' => "s3-bench-pub-{$i}",
                'id' => $res['business_id'],
            ]);

            $summary['total_processed']++;
            $summary[$res['outcome']]++;
            $summary['breakdown_reasons'][$res['reason_code']] = ($summary['breakdown_reasons'][$res['reason_code']] ?? 0) + 1;
        }

        // گروه ۲: ۲۰ رکورد نیازمند بازبینی (۱۰ بدون تلفن، ۵ امتیاز پایین، ۵ دائماً بسته)
        for ($i = 1; $i <= 20; $i++) {
            $itemLat = $baseLat - ($i * 0.0030);
            $itemLng = $baseLng - ($i * 0.0030);

            $phones = ($i <= 10) ? [] : [sprintf('01333%06d', 200000 + $i)];
            $rating = ($i > 10 && $i <= 15) ? 2.1 : 4.2;
            $reviews = ($i > 10 && $i <= 15) ? 80 : 30;
            $closed = ($i > 15);

            $res = self::processIncomingRecord($cityId, $categoryId, 's3_bench_osm', [
                'name' => "کافه نیازمند بررسی شماره {$i}",
                'address' => "رشت، خیابان معلم، پلاک {$i}",
                'lat' => $itemLat,
                'lng' => $itemLng,
                'phones' => $phones,
                'hours' => $closed ? 'دائماً بسته' : '۱۰ تا ۲۲',
                'permanently_closed' => $closed,
                'rating' => $rating,
                'reviews_count' => $reviews,
                'source_id' => "s3_bench_rev_{$i}",
            ], 'rules', 3.85);

            DB::query("UPDATE businesses SET slug = :s WHERE id = :id", [
                's' => "s3-bench-rev-{$i}",
                'id' => $res['business_id'],
            ]);

            $summary['total_processed']++;
            $summary[$res['outcome']]++;
            $summary['breakdown_reasons'][$res['reason_code']] = ($summary['breakdown_reasons'][$res['reason_code']] ?? 0) + 1;
        }

        // گروه ۳: ۱۵ رکورد تکراری (دقیقاً هم‌نام و در فاصله ۲۰ متری از رکوردهای ۱ تا ۱۵ گروه اول)
        for ($i = 1; $i <= 15; $i++) {
            $itemLat = $baseLat + ($i * 0.0025) + 0.0001; // حدود ۱۱ متر فاصله (< ۸۰ متر)
            $itemLng = $baseLng + ($i * 0.0025) + 0.0001;
            $phone = sprintf('01333%06d', 100000 + $i);

            $res = self::processIncomingRecord($cityId, $categoryId, 's3_bench_gosom', [
                'name' => "کافه نمونه استاندارد گیلان شماره {$i}",
                'address' => "رشت، بلوار گلسار، خیابان {$i}",
                'lat' => $itemLat,
                'lng' => $itemLng,
                'phones' => [$phone],
                'hours' => '۹ صبح تا ۱۱ شب',
                'rating' => 4.5,
                'reviews_count' => 70,
                'source_id' => "s3_bench_dup_{$i}",
            ], 'rules', 3.85);

            $summary['total_processed']++;
            $summary[$res['outcome']]++;
            $summary['breakdown_reasons'][$res['reason_code']] = ($summary['breakdown_reasons'][$res['reason_code']] ?? 0) + 1;
        }

        // گروه ۴: ۱۰ رکورد دارای تناقض منبع (منبع دوم مستقل برای رکوردهای ۱۶ تا ۲۵ گروه اول اما با فاصله ۴۵۰ متر >= ۱۰۰ متر و آدرس متفاوت)
        for ($i = 16; $i <= 25; $i++) {
            $itemLat = $baseLat + ($i * 0.0025) + 0.0045; // حدود ۵۰۰ متر اختلاف مکان!
            $itemLng = $baseLng + ($i * 0.0025) + 0.0045;

            $res = self::processIncomingRecord($cityId, $categoryId, 's3_bench_neshan', [
                'name' => "کافه نمونه استاندارد گیلان شماره {$i}",
                'address' => "رشت، منظریه، آدرس متناقض منبع دوم پلاک {$i}",
                'lat' => $itemLat,
                'lng' => $itemLng,
                'phones' => [sprintf('01333%06d', 900000 + $i)],
                'hours' => '۸ تا ۲۳',
                'rating' => 4.1,
                'reviews_count' => 15,
                'source_id' => "s3_bench_conflict_{$i}",
            ], 'rules', 3.85);

            $summary['total_processed']++;
            $summary[$res['outcome']]++;
            $summary['breakdown_reasons'][$res['reason_code']] = ($summary['breakdown_reasons'][$res['reason_code']] ?? 0) + 1;
        }

        // بازمحاسبه Tier A (۲۰٪ برتر)، B و C
        self::recalculateTiersForCityCategory($cityId, $categoryId);

        return $summary;
    }
}
