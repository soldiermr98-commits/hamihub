<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
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

        // همگام‌سازی خودکار کسب‌وکارهایی که در وضعیت pending_review هستند ولی در جدول review_queue رکورد فعالی ندارند
        $unsyncedBiz = DB::fetchAll(
            "SELECT b.id, b.name, b.address, b.internal_score, b.publish_reason
             FROM businesses b
             LEFT JOIN review_queue rq ON (rq.kind = 'business' AND rq.target_id = b.id AND rq.status = 'pending')
             WHERE b.status = 'pending_review' AND rq.id IS NULL
             LIMIT 100"
        );
        foreach ($unsyncedBiz as $ub) {
            $reason = trim((string) ($ub['publish_reason'] ?? ''));
            if ($reason === '' || $reason === 'missing_phone_or_low_score') {
                $reason = 'missing_valid_phone';
            }
            DB::query(
                "INSERT INTO review_queue (kind, target_id, reason_code, payload_json, status, created_at)
                 VALUES ('business', :tid, :rc, :p, 'pending', NOW())",
                [
                    'tid' => (int) $ub['id'],
                    'rc' => $reason,
                    'p' => json_encode([
                        'business_id' => (int) $ub['id'],
                        'business_name' => (string) $ub['name'],
                        'address' => (string) ($ub['address'] ?? ''),
                        'score' => (float) ($ub['internal_score'] ?? 3.9),
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        }

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

        foreach ($cleanIds as $rqId) {
            $item = DB::fetchOne("SELECT * FROM review_queue WHERE id = :id LIMIT 1", ['id' => $rqId]);
            if ($item === null) {
                continue;
            }

            $kind = (string) $item['kind'];
            $targetId = (int) $item['target_id'];
            $payload = json_decode((string) ($item['payload_json'] ?? '{}'), true) ?: [];

            DB::query(
                "UPDATE review_queue
                 SET status = :st, decided_by = :uid, decided_at = NOW()
                 WHERE id = :id",
                [
                    'st' => $decision,
                    'uid' => (int) $user['id'],
                    'id' => $rqId,
                ]
            );

            // اعمال اثر تصمیم روی موجودیت هدف
            if ($kind === 'business') {
                $newBizStatus = $decision === 'approved' ? 'published' : 'rejected';
                DB::query(
                    "UPDATE businesses
                     SET status = :st,
                         publish_reason = :pr,
                         last_verified_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id",
                    [
                        'st' => $newBizStatus,
                        'pr' => $decision === 'approved' ? 'approved_in_review_queue' : 'rejected_in_review_queue',
                        'id' => $targetId,
                    ]
                );
            } elseif ($kind === 'change') {
                $field = (string) ($payload['field'] ?? '');
                $newVal = (string) ($payload['new_value'] ?? '');

                if ($decision === 'approved' && $targetId > 0 && $newVal !== '') {
                    if ($field === 'address') {
                        DB::query("UPDATE businesses SET address = :v, updated_at = NOW() WHERE id = :id", [
                            'v' => $newVal,
                            'id' => $targetId,
                        ]);
                    } elseif ($field === 'phone') {
                        DB::query(
                            "UPDATE business_contacts SET value = :v, last_verified_at = NOW()
                             WHERE business_id = :bid AND is_primary = 1",
                            ['v' => $newVal, 'bid' => $targetId]
                        );
                    }
                }

                DB::query(
                    "UPDATE change_log SET status = :st WHERE business_id = :bid AND status = 'pending'",
                    ['st' => $decision, 'bid' => $targetId]
                );
            } elseif ($kind === 'article') {
                $newArtStatus = $decision === 'approved' ? 'published' : 'draft';
                DB::query(
                    "UPDATE articles
                     SET status = :st,
                         published_at = CASE WHEN :st2 = 'published' THEN NOW() ELSE published_at END,
                         updated_at = NOW()
                     WHERE id = :id",
                    ['st' => $newArtStatus, 'st2' => $newArtStatus, 'id' => $targetId]
                );
                Cache::flushAll();
            } elseif ($kind === 'claim') {
                DB::query("UPDATE claims SET status = :st WHERE id = :id", ['st' => $decision, 'id' => $targetId]);
            } elseif ($kind === 'report') {
                $repStatus = $decision === 'approved' ? 'resolved' : 'rejected';
                DB::query("UPDATE reports SET status = :st WHERE id = :id", ['st' => $repStatus, 'id' => $targetId]);
            }

            $processedCount++;
        }

        Response::json([
            'ok' => true,
            'processed_count' => $processedCount,
            'decision' => $decision,
            'message' => sprintf(
                '%d مورد در صندوق بازبینی با موفقیت «%s» شد.',
                $processedCount,
                $decision === 'approved' ? 'تأیید و منتشر' : 'رد'
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
        Auth::requireRole(['admin', 'editor']);

        $cityId = (int) $request->input('city_id', 1);
        $categoryId = (int) $request->input('category_id', 1);

        $summary = PipelineService::runPredictable100Benchmark($cityId, $categoryId);

        Response::json([
            'ok' => true,
            'summary' => $summary,
            'message' => sprintf(
                'پایپ‌لاین S3 روی %d رکورد نمونه اجرا شد: %d منتشرشده | %d بازبینی | %d تکراری | %d تناقض منبع.',
                $summary['total_processed'],
                $summary['published'],
                $summary['pending_review'],
                $summary['duplicate'],
                $summary['source_conflict']
            ),
        ]);
    }
}
