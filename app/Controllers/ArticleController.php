<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\SlugService;

/**
 * کنترلر مدیریت مقالات لیستی («بهترین X در شهر Y») و جایگاه‌های اسپانسرشیپ (مرحله S4)
 * منطبق با جداول articles, article_items, article_faqs در 001_initial_schema.sql
 */
final class ArticleController
{
    /**
     * GET /api/v1/articles
     */
    public static function index(Request $req, array $params = []): void
    {
        Auth::requireRole(['admin', 'editor']);

        $articles = DB::fetchAll(
            "SELECT a.*,
                    c.name_fa AS city_name_fa, c.slug AS city_slug,
                    cat.name_fa AS category_name_fa, cat.slug AS category_slug
             FROM articles a
             INNER JOIN cities c ON c.id = a.city_id
             LEFT JOIN categories cat ON cat.id = a.category_id
             ORDER BY a.id DESC"
        );

        foreach ($articles as &$art) {
            $aid = (int)$art['id'];
            $items = DB::fetchAll(
                "SELECT ai.*,
                        b.name AS business_name_fa,
                        b.slug AS business_slug,
                        b.internal_score AS weighted_score,
                        b.tier
                 FROM article_items ai
                 INNER JOIN businesses b ON b.id = ai.business_id
                 WHERE ai.article_id = :aid
                 ORDER BY ai.position ASC, ai.id ASC",
                ['aid' => $aid]
            );
            foreach ($items as &$it) {
                $hl = !empty($it['highlights']) ? (json_decode((string)$it['highlights'], true) ?: []) : [];
                $it['rank_num'] = (int)$it['position'];
                $it['badge_fa'] = (string)($hl['badge'] ?? '');
                $it['verdict_fa'] = (string)($hl['verdict'] ?? '');
                $it['custom_desc_html'] = (string)($it['custom_desc'] ?? '');
            }
            unset($it);

            $faqs = DB::fetchAll(
                "SELECT id, article_id, question AS question_fa, answer AS answer_fa, sort AS sort_order
                 FROM article_faqs
                 WHERE article_id = :aid
                 ORDER BY sort ASC, id ASC",
                ['aid' => $aid]
            );

            $art['title_fa'] = (string)$art['title'];
            $art['subtitle_fa'] = (string)($art['meta_description'] ?? '');
            $art['intro_html'] = (string)($art['intro'] ?? '');
            $art['methodology_html'] = (string)($art['tips_html'] ?? '');
            $art['items'] = $items;
            $art['faqs'] = $faqs;
        }
        unset($art);

        Response::json([
            'ok'       => true,
            'articles' => $articles,
        ]);
    }

    /**
     * POST /api/v1/articles
     * ذخیره یا ویرایش مقاله لیستی، آیتم‌ها، وضعیت اسپانسرشیپ و سؤالات متداول + ابطال کش صفحات عمومی
     */
    public static function storeOrUpdate(Request $req, array $params = []): void
    {
        Csrf::verifyOrFail($req);
        $user = Auth::requireRole(['admin', 'editor']);

        $id = (int)$req->input('id', 0);
        $cityId = (int)$req->input('city_id', 0);
        $categoryId = (int)$req->input('category_id', 0);
        $titleFa = trim((string)($req->input('title_fa', $req->input('title', ''))));
        $subtitleFa = trim((string)($req->input('subtitle_fa', $req->input('meta_description', ''))));
        $slugInput = trim((string)$req->input('slug', ''));
        $introHtml = trim((string)($req->input('intro_html', $req->input('intro', ''))));
        $methodologyHtml = trim((string)($req->input('methodology_html', $req->input('tips_html', ''))));
        $status = (string)$req->input('status', 'draft');
        if (!in_array($status, ['draft', 'review', 'published'], true)) {
            $status = 'draft';
        }
        if ($user['role'] !== 'admin' && $status === 'published') { $status = 'review'; }
        $metaTitle = trim((string)$req->input('meta_title', '')) ?: ($titleFa . ' | حامی‌هاب گیلان');
        $metaDesc = trim((string)$req->input('meta_description', '')) ?: $subtitleFa;

        if ($cityId <= 0 || $titleFa === '') {
            Response::json([
                'ok'    => false,
                'error' => 'انتخاب شهر و وارد کردن عنوان مقاله لیستی الزامی است.',
            ], 422);
            return;
        }

        if ($categoryId <= 0) {
            $firstCat = DB::fetchOne("SELECT id FROM categories ORDER BY id ASC LIMIT 1");
            $categoryId = (int)($firstCat['id'] ?? 1);
        }

        $rawSlug = $slugInput !== '' ? $slugInput : SlugService::suggestSlug($titleFa, 'best');
        $slugCheck = SlugService::validateSlug($rawSlug);
        if (!$slugCheck['ok']) Response::json(['ok'=>false,'error'=>$slugCheck['error'],'field'=>'slug'],422);
        if (!DB::fetchOne('SELECT id FROM cities WHERE id=:id', ['id'=>$cityId]) || !DB::fetchOne('SELECT id FROM categories WHERE id=:id', ['id'=>$categoryId])) Response::json(['ok'=>false,'error'=>'شهر یا دسته‌بندی یافت نشد.'],422);
        $slug = SlugService::ensureUniqueSlug('articles', strtolower($rawSlug), $id > 0 ? $id : null);

        if ($id > 0 && !DB::fetchOne('SELECT id FROM articles WHERE id=:id', ['id'=>$id])) Response::json(['ok'=>false,'error'=>'مقاله یافت نشد.'], 404);
        $pdo = DB::connection();
        $pdo->beginTransaction();
        if ($id > 0) {
            DB::query(
                "UPDATE articles SET
                    city_id = :city_id,
                    category_id = :cat_id,
                    slug = :slug,
                    title = :title,
                    meta_title = :meta_title,
                    meta_description = :meta_desc,
                    intro = :intro,
                    tips_html = :tips,
                    status = :status,
                    published_at = CASE WHEN :status2 = 'published' AND published_at IS NULL THEN NOW() ELSE published_at END,
                    updated_at = NOW()
                 WHERE id = :id",
                [
                    'city_id'    => $cityId,
                    'cat_id'     => $categoryId,
                    'slug'       => $slug,
                    'title'      => $titleFa,
                    'meta_title' => $metaTitle,
                    'meta_desc'  => $metaDesc,
                    'intro'      => $introHtml !== '' ? $introHtml : null,
                    'tips'       => $methodologyHtml !== '' ? $methodologyHtml : null,
                    'status'     => $status,
                    'status2'    => $status,
                    'id'         => $id,
                ]
            );
            $articleId = $id;
        } else {
            DB::query(
                "INSERT INTO articles (
                    title, slug, meta_title, meta_description, category_id, city_id,
                    intro, tips_html, year_label, status, ai_generated, published_at, created_at, updated_at
                 ) VALUES (
                    :title, :slug, :meta_title, :meta_desc, :cat_id, :city_id,
                    :intro, :tips, '', :status, 0,
                    CASE WHEN :status2 = 'published' THEN NOW() ELSE NULL END, NOW(), NOW()
                 )",
                [
                    'title'      => $titleFa,
                    'slug'       => $slug,
                    'meta_title' => $metaTitle,
                    'meta_desc'  => $metaDesc,
                    'cat_id'     => $categoryId,
                    'city_id'    => $cityId,
                    'intro'      => $introHtml !== '' ? $introHtml : null,
                    'tips'       => $methodologyHtml !== '' ? $methodologyHtml : null,
                    'status'     => $status,
                    'status2'    => $status,
                ]
            );
            $articleId = DB::lastInsertId();
        }

        // بروزرسانی آیتم‌های مقاله
        $items = $req->input('items');
        if (is_array($items)) {
            DB::query("DELETE FROM article_items WHERE article_id = :aid", ['aid' => $articleId]);
            $pos = 1;
            foreach ($items as $it) {
                $bid = (int)($it['business_id'] ?? 0);
                if ($bid <= 0) {
                    continue;
                }
                $isSponsored = !empty($it['is_sponsored']) ? 1 : 0;
                $highlights = json_encode([
                    'badge'   => trim((string)($it['badge_fa'] ?? '')),
                    'verdict' => trim((string)($it['verdict_fa'] ?? '')),
                    'pros'    => is_array($it['pros'] ?? null) ? $it['pros'] : [],
                    'cons'    => is_array($it['cons'] ?? null) ? $it['cons'] : [],
                ], JSON_UNESCAPED_UNICODE);

                DB::query(
                    "INSERT IGNORE INTO article_items (
                        article_id, business_id, position, custom_desc, highlights,
                        is_sponsored, sponsor_label, sponsor_start, sponsor_end
                     ) VALUES (
                        :aid, :bid, :pos, :desc, :hl,
                        :is_spon, :spon_label, :spon_start, :spon_end
                     )",
                    [
                        'aid'        => $articleId,
                        'bid'        => $bid,
                        'pos'        => (int)($it['rank_num'] ?? $pos),
                        'desc'       => trim((string)($it['custom_desc_html'] ?? '')),
                        'hl'         => $highlights,
                        'is_spon'    => $isSponsored,
                        'spon_label' => trim((string)($it['sponsor_label'] ?? 'پیشنهاد ویژه')),
                        'spon_start' => !empty($it['sponsor_start']) ? $it['sponsor_start'] : ($isSponsored ? date('Y-m-d 00:00:00') : null),
                        'spon_end'   => !empty($it['sponsor_end']) ? $it['sponsor_end'] : ($isSponsored ? date('Y-m-d 23:59:59', strtotime('+30 days')) : null),
                    ]
                );
                $pos++;
            }
        }

        // بروزرسانی سؤالات متداول مقاله
        $faqs = $req->input('faqs');
        if (is_array($faqs)) {
            DB::query("DELETE FROM article_faqs WHERE article_id = :aid", ['aid' => $articleId]);
            foreach ($faqs as $idx => $f) {
                $q = trim((string)($f['question_fa'] ?? ($f['question'] ?? '')));
                $a = trim((string)($f['answer_fa'] ?? ($f['answer'] ?? '')));
                if ($q === '' || $a === '') {
                    continue;
                }
                DB::query(
                    "INSERT INTO article_faqs (article_id, question, answer, sort) VALUES (:aid, :q, :a, :sort)",
                    [
                        'aid'  => $articleId,
                        'q'    => $q,
                        'a'    => $a,
                        'sort' => $idx + 1,
                    ]
                );
            }
        }

        if ($status === 'review') {
            \App\Services\PipelineService::enqueueReviewIfNotExists('article', $articleId, 'manual_review', ['article_id'=>$articleId,'title'=>$titleFa]);
        } else {
            DB::query("UPDATE review_queue SET status=:status,decided_by=:user,decided_at=NOW() WHERE kind='article' AND target_id=:id AND status='pending'", ['status'=>$status === 'published' ? 'approved' : 'rejected','user'=>(int)$user['id'],'id'=>$articleId]);
        }
        $pdo->commit();
        Cache::flushAll();

        Response::json([
            'ok'         => true,
            'article_id' => $articleId,
            'slug'       => $slug,
            'message'    => 'مقاله لیستی با موفقیت ذخیره شد و کش صفحات عمومی به‌روزرسانی گردید.',
        ]);
    }

    /**
     * POST /api/v1/articles/auto-build
     * تولید خودکار مقاله «بهترین {دسته} در {شهر}» از کسب‌وکارهای برتر آن شهر و دسته
     */
    public static function autoBuild(Request $req, array $params = []): void
    {
        Csrf::verifyOrFail($req);
        Auth::requireRole(['admin', 'editor']);
        $cityId = (int) $req->input('city_id', 0);
        $categoryId = (int) $req->input('category_id', 0);
        $city = DB::fetchOne("SELECT id, name_fa, slug FROM cities WHERE id = :id", ['id' => $cityId]);
        $category = DB::fetchOne("SELECT id, name_fa, slug FROM categories WHERE id = :id", ['id' => $categoryId]);
        if (!$city || !$category) {
            Response::json(['ok' => false, 'error' => 'شهر و دسته معتبر را انتخاب کنید.'], 422);
        }
        $businesses = DB::fetchAll(
            "SELECT id FROM businesses WHERE city_id = :cid AND category_id = :cat AND status = 'published' ORDER BY name ASC, id ASC LIMIT 50",
            ['cid' => $cityId, 'cat' => $categoryId]
        );
        if (!$businesses) {
            Response::json(['ok' => false, 'error' => 'برای این شهر و دسته کسب‌وکار تأییدشده‌ای وجود ندارد.'], 422);
        }
        $slug = SlugService::ensureUniqueSlug('articles', 'list-' . $category['slug'] . '-' . $city['slug']);
        $title = $category['name_fa'] . ' در ' . $city['name_fa'];
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            DB::query(
                "INSERT INTO articles (title, slug, category_id, city_id, year_label, status, ai_generated, created_at, updated_at)
                 VALUES (:title, :slug, :cat, :city, '', 'draft', 0, NOW(), NOW())",
                ['title' => $title, 'slug' => $slug, 'cat' => $categoryId, 'city' => $cityId]
            );
            $articleId = DB::lastInsertId();
            foreach ($businesses as $index => $biz) {
                DB::query("INSERT INTO article_items (article_id, business_id, position, is_sponsored) VALUES (:aid, :bid, :pos, 0)",
                    ['aid' => $articleId, 'bid' => (int) $biz['id'], 'pos' => $index + 1]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::json(['ok' => true, 'article_id' => $articleId, 'slug' => $slug,
            'items_added' => count($businesses), 'message' => 'پیش‌نویس ساخته شد؛ قبل از انتشار آن را بررسی کنید.']);
    }
}
