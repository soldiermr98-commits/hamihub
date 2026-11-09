<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\ArticleService;
use App\Services\PipelineService;

/**
 * کنترلر صندوق بازبینی متمرکز (Review Queue) و تاریخچه تغییرات (change_log) در مرحله S3
 * مهم‌ترین صفحه پنل مدیریت:
 * - نمایش یکپارچه همه موارد نیازمند تصمیم (business, change, duplicate, article, claim, report)
 * - تایید و رد تکی و گروهی (Bulk Approve / Reject)
 * - اجرای بنچمارک ۱۰۰ رکورد نمونه با نتیجه قابل‌پیش‌بینی برای اثبات معیار قبولی مرحله S3
 */
final class ReviewQueueController
{
    /**
     * فهرست آیتم‌های صندوق بازبینی، تاریخچه تغییرات (change_log) و آمار وضعیت‌ها
     * GET /api/v1/review-queue
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
        $kindFilter = trim((string) ($_GET['kind'] ?? 'all'));

        $where = ["1=1"];
        $params = [];

        if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
            $where[] = "rq.status = :st";
            $params['st'] = $statusFilter;
        }

        if ($kindFilter !== '' && $kindFilter !== 'all') {
            $where[] = "rq.kind = :k";
            $params['k'] = $kindFilter;
        }

        $whereSql = implode(' AND ', $where);

        $items = DB::fetchAll(
            "SELECT rq.*,
                    b.name AS business_name,
                    b.slug AS business_slug,
                    b.address AS business_address,
                    b.internal_score AS business_score,
                    b.tier AS business_tier,
                    b.confidence AS business_confidence,
                    c.name_fa AS city_name
             FROM review_queue rq
             LEFT JOIN businesses b ON (rq.kind IN ('business', 'change', 'duplicate') AND b.id = rq.target_id)
             LEFT JOIN cities c ON c.id = b.city_id
             WHERE {$whereSql}
             ORDER BY rq.id DESC
             LIMIT 250",
            $params
        );

        $changeLogs = DB::fetchAll(
            "SELECT cl.*, b.name AS business_name, b.slug AS business_slug
             FROM change_log cl
             LEFT JOIN businesses b ON b.id = cl.business_id
             ORDER BY cl.id DESC
             LIMIT 100"
        );

        $countsRows = DB::fetchAll(
            "SELECT kind, COUNT(*) AS cnt
             FROM review_queue
             WHERE status = 'pending'
             GROUP BY kind"
        );
        $pendingByKind = [
            'all' => 0,
            'business' => 0,
            'change' => 0,
            'duplicate' => 0,
            'article' => 0,
            'claim' => 0,
            'report' => 0,
        ];
        foreach ($countsRows as $r) {
            $k = (string) $r['kind'];
            $c = (int) $r['cnt'];
            $pendingByKind[$k] = $c;
            $pendingByKind['all'] += $c;
        }

        Response::json([
            'ok' => true,
            'items' => $items,
            'change_logs' => $changeLogs,
            'pending_counts' => $pendingByKind,
        ]);
    }

    /**
     * تایید یا رد تکی یا گروهی آیتم‌های صندوق بازبینی
     * POST /api/v1/review-queue/decide
     */
    public static function decide(Request $request): void
    {
        Csrf::verifyOrFail($request);
        $user = Auth::requireRole(['admin']);

        $idsInput = $request->input('ids', []);
        if (!is_array($idsInput) || empty($idsInput)) {
            $singleId = (int) $request->input('id', 0);
            if ($singleId > 0) {
                $idsInput = [$singleId];
            }
        }

        $decision = (string) $request->input('decision', 'approved');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            Response::json(['ok' => false, 'error' => 'تصمیم ارسالی باید approved یا rejected باشد.'], 422);
        }

        $cleanIds = array_values(array_filter(array_map('intval', $idsInput), static fn(int $v): bool => $v > 0));
        if (empty($cleanIds)) {
            Response::json(['ok' => false, 'error' => 'هیچ آیتمی برای تصمیم‌گیری انتخاب نشده است.'], 422);
        }

        $processedCount = 0;
        $skipped = 0;
        $conflicts = 0;
        foreach (array_unique($cleanIds) as $rqId) {
            $pdo = DB::connection();
            $pdo->beginTransaction();
            $commitAttempted = false;
            try {
                $item = DB::fetchOne("SELECT * FROM review_queue WHERE id=:id FOR UPDATE", ['id'=>$rqId]);
                if (!$item || $item['status'] !== 'pending') { $pdo->rollBack(); $skipped++; continue; }
                $kind = (string)$item['kind'];
                $targetId = (int)$item['target_id'];
                $payload = json_decode((string)($item['payload_json'] ?? '{}'), true) ?: [];
                if ($kind === 'business') {
                    $target = DB::fetchOne("SELECT status FROM businesses WHERE id=:id FOR UPDATE", ['id'=>$targetId]);
                    if (!$target || $target['status'] !== 'pending_review') { $pdo->rollBack(); $skipped++; continue; }
                    DB::query("UPDATE businesses SET status=:status,publish_reason=:reason,last_verified_at=NOW(),updated_at=NOW() WHERE id=:id", ['status'=>$decision === 'approved'?'published':'rejected','reason'=>$decision . '_in_review_queue','id'=>$targetId]);
                } elseif ($kind === 'article') {
                    $result = ArticleService::decideReview($targetId, $decision);
                    if ($result !== 'applied') {
                        $pdo->rollBack();
                        $skipped++;
                        if ($result === 'conflict') $conflicts++;
                        continue;
                    }
                } elseif ($kind === 'change') {
                    $field = (string)($payload['field'] ?? '');
                    $new = (string)($payload['new_value'] ?? '');
                    $old = (string)($payload['old_value'] ?? '');
                    $target = DB::fetchOne("SELECT * FROM businesses WHERE id=:id FOR UPDATE", ['id'=>$targetId]);
                    if (!$target || !in_array($field, ['address','name','hours','coordinates','status','phone'], true) || $new === '') { $pdo->rollBack(); $skipped++; continue; }
                    $phoneRow = null;
                    if ($field === 'phone') {
                        $phoneRow = DB::fetchOne("SELECT id,value FROM business_contacts WHERE business_id=:id AND type IN ('phone','mobile') ORDER BY is_primary DESC,id ASC LIMIT 1 FOR UPDATE", ['id'=>$targetId]);
                        $current = (string)($phoneRow['value'] ?? '');
                    } elseif ($field === 'coordinates') $current = ($target['lat'] ?? '') . ',' . ($target['lng'] ?? '');
                    else $current = (string)($target[$field] ?? '');
                    // Never apply an old proposal over a newer manual or approved value.
                    if ($decision === 'approved' && $current !== $old) { $pdo->rollBack(); $skipped++; continue; }
                    if ($decision === 'approved') {
                        if ($field === 'name') DB::query("UPDATE businesses SET name=:v,normalized_name=:n,updated_at=NOW() WHERE id=:id", ['v'=>$new,'n'=>PipelineService::canonicalNameKey($new),'id'=>$targetId]);
                        elseif (in_array($field, ['address','hours'], true)) DB::query("UPDATE businesses SET {$field}=:v,updated_at=NOW() WHERE id=:id", ['v'=>$new,'id'=>$targetId]);
                        elseif ($field === 'coordinates') {
                            $coords=explode(',', $new);
                            if (count($coords)!==2 || !is_numeric($coords[0]) || !is_numeric($coords[1]) || abs((float)$coords[0])>90 || abs((float)$coords[1])>180) { $pdo->rollBack(); $skipped++; continue; }
                            DB::query("UPDATE businesses SET lat=:lat,lng=:lng,updated_at=NOW() WHERE id=:id", ['lat'=>(float)$coords[0],'lng'=>(float)$coords[1],'id'=>$targetId]);
                        } elseif ($field === 'status') {
                            if ($new !== 'hidden') { $pdo->rollBack(); $skipped++; continue; }
                            DB::query("UPDATE businesses SET status='hidden',updated_at=NOW() WHERE id=:id", ['id'=>$targetId]);
                        } elseif ($field === 'phone') {
                            $phone=\App\Services\SlugService::normalizePhone($new);
                            if ($phone === '') { $pdo->rollBack(); $skipped++; continue; }
                            $type = str_starts_with($phone, '+989') ? 'mobile' : 'phone';
                            if ($phoneRow) DB::query("UPDATE business_contacts SET value=:v,type=:type,source='manual',confidence='high',last_verified_at=NOW() WHERE id=:id", ['v'=>$phone,'type'=>$type,'id'=>(int)$phoneRow['id']]);
                            else DB::query("INSERT INTO business_contacts (business_id,type,value,is_primary,source,confidence,last_verified_at) VALUES (:id,:type,:v,1,'manual','high',NOW())", ['id'=>$targetId,'type'=>$type,'v'=>$phone]);
                        }
                    }
                    DB::query("UPDATE change_log SET status=:status WHERE id=:id AND business_id=:bid AND status='pending'", ['status'=>$decision,'id'=>(int)($payload['change_id'] ?? 0),'bid'=>$targetId]);
                } elseif ($kind === 'claim') DB::query("UPDATE claims SET status=:status WHERE id=:id AND status='pending'", ['status'=>$decision,'id'=>$targetId]);
                elseif ($kind === 'report') DB::query("UPDATE reports SET status=:status WHERE id=:id AND status='pending'", ['status'=>$decision==='approved'?'resolved':'rejected','id'=>$targetId]);
                else { $pdo->rollBack(); $skipped++; continue; }
                DB::query("UPDATE review_queue SET status=:status,decided_by=:uid,decided_at=NOW() WHERE id=:id", ['status'=>$decision,'uid'=>(int)$user['id'],'id'=>$rqId]);
                // The connection can fail while COMMIT's outcome is unknown.
                // Invalidate even then, and before processing the next item.
                $commitAttempted = true;
                $pdo->commit();
                $processedCount++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            } finally {
                if ($commitAttempted) Cache::flushAll();
            }
        }

        Response::json([
            'ok' => true,
            'processed_count' => $processedCount,
            'skipped_count' => $skipped,
            'conflict_count' => $conflicts,
            'decision' => $decision,
            'message' => sprintf(
                '%d مورد «%s» شد؛ %d مورد قبلاً بررسی شده یا تغییر کرده و اعمال نشد.%s',
                $processedCount,
                $decision === 'approved' ? 'تأیید' : 'رد',
                $skipped,
                $conflicts ? ' نسخه منتشرشده یا نشانی بعضی مقالات تغییر کرده است؛ ابتدا نسخه‌ها را بررسی کنید.' : ''
            ),
        ]);
    }

    /**
     * اجرای بنچمارک ۱۰۰ رکورد نمونه با نتیجه قابل‌پیش‌بینی (معیار قبولی مرحله S3)
     * POST /api/v1/pipeline/benchmark-100
     */
    public static function runBenchmark100(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        Response::json(['ok' => false, 'error' => 'بنچمارک داده‌ساز در محیط عملیاتی غیرفعال است.'], 410);
    }
}
