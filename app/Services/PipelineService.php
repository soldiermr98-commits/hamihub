<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use Throwable;

/** Collection is evidence, not verification. Publication and changes require a human decision. */
final class PipelineService
{
    public static function distanceMeters(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return round(12742000 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))), 2);
    }

    public static function canonicalNameKey(string $name): string
    {
        $name = SlugService::toEnglishDigits(SlugService::normalizePersianText($name));
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name, 'UTF-8'));
    }

    /**
     * Input source IDs must be stable; a name alone is not a reliable identity.
     * Known sources are updated in place. Cross-source matches require the same name
     * and either close coordinates or a shared, valid phone when coordinates are absent.
     */
    public static function processIncomingRecord(
        int $cityId, int $defaultCategoryId, string $provider, array $rawItem,
        string $cityPublishMode = 'manual', float $minScoreThreshold = 0.0
    ): array {
        $name = mb_substr(SlugService::normalizePersianText((string) ($rawItem['name'] ?? '')), 0, 200);
        $key = self::canonicalNameKey($name);
        $sourceId = trim((string) ($rawItem['source_id'] ?? ''));
        if ($cityId <= 0 || $defaultCategoryId <= 0 || $key === '' || $sourceId === '' || strlen($sourceId) > 190
            || !preg_match('/^[a-z0-9_-]{1,50}$/', $provider)) {
            throw new InvalidArgumentException('رکورد فاقد نام، شهر، دسته یا شناسه معتبر منبع است.');
        }
        $address = SlugService::normalizePersianText((string) ($rawItem['address'] ?? ''));
        $hours = SlugService::normalizePersianText((string) ($rawItem['hours'] ?? ''));
        $lat = isset($rawItem['lat']) && is_numeric($rawItem['lat']) ? (float) $rawItem['lat'] : null;
        $lng = isset($rawItem['lng']) && is_numeric($rawItem['lng']) ? (float) $rawItem['lng'] : null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) $lat = $lng = null;
        $phones = [];
        foreach ((array) ($rawItem['phones'] ?? []) as $phone) {
            $normalized = SlugService::normalizePhone((string) $phone);
            if ($normalized !== '' && !in_array($normalized, $phones, true)) $phones[] = $normalized;
        }
        $closed = !empty($rawItem['permanently_closed']) || str_contains($hours, 'دائماً بسته');
        $rating = isset($rawItem['rating']) && is_numeric($rawItem['rating']) ? (float) $rawItem['rating'] : null;
        if ($rating !== null && ($rating < 1 || $rating > 5)) $rating = null;
        $reviews = max(0, (int) ($rawItem['reviews_count'] ?? 0));
        $score = $rating !== null && $reviews > 0 ? $rating : 0.0;
        $raw = json_encode($rawItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($raw === false) throw new InvalidArgumentException('داده منبع قابل ذخیره نیست.');

        $pdo = DB::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $existing = DB::fetchOne(
                "SELECT b.* FROM business_sources s INNER JOIN businesses b ON b.id = s.business_id
                 WHERE s.provider = :p AND s.source_id = :sid LIMIT 1 FOR UPDATE",
                ['p' => $provider, 'sid' => $sourceId]
            );
            if ($existing !== null && (int) $existing['city_id'] !== $cityId) {
                throw new InvalidArgumentException('این شناسه منبع قبلاً برای شهر دیگری ثبت شده است.');
            }
            if ($existing === null) {
                $candidates = DB::fetchAll(
                    "SELECT * FROM businesses WHERE city_id = :city AND category_id = :cat AND normalized_name = :name ORDER BY id ASC",
                    ['city' => $cityId, 'cat' => $defaultCategoryId, 'name' => $key]
                );
                foreach ($candidates as $candidate) {
                    $distance = self::distanceMeters($lat, $lng,
                        $candidate['lat'] !== null ? (float) $candidate['lat'] : null,
                        $candidate['lng'] !== null ? (float) $candidate['lng'] : null);
                    if ($distance !== null && $distance <= 80) { $existing = $candidate; break; }
                    if ($distance === null && $phones) {
                        $known = DB::fetchAll("SELECT value FROM business_contacts WHERE business_id = :id AND type IN ('phone','mobile')", ['id' => (int) $candidate['id']]);
                        if (array_intersect($phones, array_column($known, 'value'))) { $existing = $candidate; break; }
                    }
                }
            }
            if ($existing !== null) {
                $id = (int) $existing['id'];
                DB::query("INSERT INTO business_sources (business_id, provider, source_id, raw, rating, reviews_count, fetched_at)
                    VALUES (:id,:provider,:sid,:raw,:rating,:reviews,NOW())
                    ON DUPLICATE KEY UPDATE raw=VALUES(raw), rating=VALUES(rating), reviews_count=VALUES(reviews_count), fetched_at=NOW()",
                    ['id'=>$id,'provider'=>$provider,'sid'=>$sourceId,'raw'=>$raw,'rating'=>$rating,'reviews'=>$reviews]);
                DB::query("UPDATE businesses SET last_collected_at = NOW() WHERE id = :id", ['id' => $id]);
                foreach (['name' => $name, 'address' => $address, 'hours' => $hours] as $field => $value) {
                    if ($value !== '' && $value !== (string) ($existing[$field] ?? '')) {
                        self::enqueueChange($id, $field, (string) ($existing[$field] ?? ''), $value, $provider);
                    }
                }
                if ($lat !== null && $lng !== null && ($existing['lat'] === null || $existing['lng'] === null)) {
                    self::enqueueChange($id, 'coordinates', ($existing['lat'] ?? '') . ',' . ($existing['lng'] ?? ''), $lat . ',' . $lng, $provider);
                } elseif ($lat !== null && $lng !== null && $existing['lat'] !== null && $existing['lng'] !== null) {
                    $distance = self::distanceMeters($lat, $lng, (float) $existing['lat'], (float) $existing['lng']);
                    if ($distance !== null && $distance > 30) {
                        self::enqueueChange($id, 'coordinates', $existing['lat'] . ',' . $existing['lng'], $lat . ',' . $lng, $provider);
                    }
                }
                if ($phones) {
                    $old = DB::fetchOne("SELECT value FROM business_contacts WHERE business_id=:id AND type IN ('phone','mobile') ORDER BY is_primary DESC,id ASC LIMIT 1", ['id'=>$id]);
                    $oldPhone = (string) ($old['value'] ?? '');
                    if ($phones[0] !== $oldPhone) self::enqueueChange($id, 'phone', $oldPhone, $phones[0], $provider);
                }
                if ($closed && $existing['status'] === 'published') {
                    self::enqueueChange($id, 'status', 'published', 'hidden', $provider);
                }
                if ($ownsTransaction) $pdo->commit();
                return ['outcome'=>'duplicate','reason_code'=>'existing_source_or_nearby_match','business_id'=>$id,
                    'is_new'=>false,'in_review_queue'=>true,'score'=>(float) $existing['internal_score']];
            }

            $reason = $closed ? 'permanently_closed' : (!$phones ? 'missing_valid_phone' : 'manual_review');
            $slug = SlugService::ensureUniqueSlug('businesses', SlugService::suggestSlug($name, 'biz'));
            DB::query("INSERT INTO businesses (name, normalized_name, slug, category_id, city_id, address, lat, lng, hours,
                status, tier, internal_score, confidence, publish_reason, last_collected_at, created_at, updated_at)
                VALUES (:name,:norm,:slug,:cat,:city,:addr,:lat,:lng,:hours,'pending_review','C',:score,'low',:reason,NOW(),NOW(),NOW())",
                ['name'=>$name,'norm'=>$key,'slug'=>$slug,'cat'=>$defaultCategoryId,'city'=>$cityId,
                 'addr'=>$address !== '' ? $address : null,'lat'=>$lat,'lng'=>$lng,'hours'=>$hours !== '' ? $hours : null,
                 'score'=>$score,'reason'=>$reason]);
            $id = DB::lastInsertId();
            DB::query("INSERT INTO business_sources (business_id,provider,source_id,raw,rating,reviews_count,fetched_at) VALUES (:id,:p,:sid,:raw,:rating,:reviews,NOW())",
                ['id'=>$id,'p'=>$provider,'sid'=>$sourceId,'raw'=>$raw,'rating'=>$rating,'reviews'=>$reviews]);
            foreach ($phones as $index => $phone) {
                DB::query("INSERT INTO business_contacts (business_id,type,value,is_primary,source,confidence,last_verified_at) VALUES (:id,:type,:value,:primary,:source,'low',NULL)",
                    ['id'=>$id,'type'=>str_starts_with($phone, '+989') ? 'mobile' : 'phone','value'=>$phone,'primary'=>$index === 0 ? 1 : 0,'source'=>$provider]);
            }
            self::enqueueReviewIfNotExists('business', $id, $reason, [
                'business_id'=>$id, 'business_name'=>$name, 'address'=>$address, 'phones'=>$phones,
                'raw_phones'=>(array) ($rawItem['phones'] ?? []), 'provider'=>$provider, 'source_id'=>$sourceId,
                'source_url'=>(string) ($rawItem['source_url'] ?? ''), 'permanently_closed'=>$closed,
            ]);
            if ($ownsTransaction) $pdo->commit();
            return ['outcome'=>'pending_review','reason_code'=>$reason,'business_id'=>$id,'is_new'=>true,'in_review_queue'=>true,'score'=>$score];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function enqueueChange(int $id, string $field, string $old, string $new, string $provider): void
    {
        $pending = DB::fetchOne("SELECT id FROM change_log WHERE business_id=:bid AND field=:field AND new_value=:value AND status='pending' LIMIT 1",
            ['bid'=>$id, 'field'=>$field, 'value'=>$new]);
        if ($pending) return;
        DB::query("INSERT INTO change_log (business_id,field,old_value,new_value,source,status,created_at) VALUES (:bid,:field,:old,:new,:source,'pending',NOW())",
            ['bid'=>$id,'field'=>$field,'old'=>$old,'new'=>$new,'source'=>$provider]);
        $changeId = DB::lastInsertId();
        DB::query("INSERT INTO review_queue (kind,target_id,reason_code,payload_json,status,created_at) VALUES ('change',:id,:reason,:payload,'pending',NOW())",
            ['id'=>$id,'reason'=>$field . '_changed','payload'=>json_encode(['business_id'=>$id,'change_id'=>$changeId,
                'field'=>$field,'old_value'=>$old,'new_value'=>$new,'source'=>$provider], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public static function enqueueReviewIfNotExists(string $kind, int $targetId, string $reasonCode, array $payload): void
    {
        if (DB::fetchOne("SELECT id FROM review_queue WHERE kind=:kind AND target_id=:id AND status='pending' LIMIT 1", ['kind'=>$kind,'id'=>$targetId])) return;
        DB::query("INSERT INTO review_queue (kind,target_id,reason_code,payload_json,status,created_at) VALUES (:kind,:id,:reason,:payload,'pending',NOW())",
            ['kind'=>$kind,'id'=>$targetId,'reason'=>$reasonCode,'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    // Kept for older callers; no fabricated ranking or tier is derived from missing ratings.
    public static function recalculateTiersForCityCategory(int $cityId, int $categoryId): void {}
}
