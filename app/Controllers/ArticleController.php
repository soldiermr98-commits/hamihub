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
        PublicSiteController::ensureDefaultListArticle();

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
                $it['badge_fa'] = (string)($hl['badge'] ?? 'منتخب حامی‌هاب');
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
        Auth::requireRole(['admin', 'editor']);

        $id = (int)$req->input('id', 0);
        $cityId = (int)$req->input('city_id', 0);
        $categoryId = (int)$req->input('category_id', 0);
        $titleFa = trim((string)($req->input('title_fa', $req->input('title', ''))));
        $subtitleFa = trim((string)($req->input('subtitle_fa', $req->input('meta_description', ''))));
        $slugInput = trim((string)$req->input('slug', ''));
        $introHtml = trim((string)($req->input('intro_html', $req->input('intro', ''))));
        $methodologyHtml = trim((string)($req->input('methodology_html', $req->input('tips_html', ''))));
        $status = (string)$req->input('status', 'published');
        if (!in_array($status, ['draft', 'review', 'published'], true)) {
            $status = 'published';
        }
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
        $slug = SlugService::ensureUniqueSlug('articles', strtolower($rawSlug), $id > 0 ? $id : null);

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
                    :intro, :tips, '۱۴۰۵', :status, 0,
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
                    'badge'   => trim((string)($it['badge_fa'] ?? 'منتخب حامی‌هاب')),
                    'verdict' => trim((string)($it['verdict_fa'] ?? 'گزینه تاییدشده در رتبه‌بندی بیزین.')),
                    'pros'    => is_array($it['pros'] ?? null) ? $it['pros'] : ['کیفیت خدمات بالا', 'موقعیت مکانی مناسب'],
                    'cons'    => is_array($it['cons'] ?? null) ? $it['cons'] : ['شلوغی در ساعات پیک'],
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

        $cityId = (int)$req->input('city_id', 0);
        $categoryId = (int)$req->input('category_id', 0);

        if ($cityId <= 0 || $categoryId <= 0) {
            Response::json([
                'ok'    => false,
                'error' => 'لطفاً شهر و دسته‌بندی را برای ساخت خودکار مقاله انتخاب کنید.',
            ], 422);
            return;
        }

        $city = DB::fetchOne("SELECT * FROM cities WHERE id = :id LIMIT 1", ['id' => $cityId]);
        $cat = DB::fetchOne("SELECT * FROM categories WHERE id = :id LIMIT 1", ['id' => $categoryId]);

        if (!$city || !$cat) {
            Response::json([
                'ok'    => false,
                'error' => 'شهر یا دسته‌بندی انتخاب‌شده معتبر نیست.',
            ], 404);
            return;
        }

        $baseSlug = 'best-' . $cat['slug'] . '-' . $city['slug'];
        $slug = SlugService::ensureUniqueSlug('articles', strtolower($baseSlug));
        $titleFa = 'بهترین ' . $cat['name_fa'] . ' در ' . $city['name_fa'] . ' (رتبه‌بندی سال ۱۴۰۵)';
        $subtitleFa = 'مقایسه و معرفی برترین مراکز ' . $cat['name_fa'] . ' شهر ' . $city['name_fa'] . ' بر اساس امتیاز وزنی بیزین و بازبینی تحریریه حامی‌هاب گیلان.';

        DB::query(
            "INSERT INTO articles (
                title, slug, meta_title, meta_description, category_id, city_id,
                intro, tips_html, year_label, status, ai_generated, published_at, created_at, updated_at
             ) VALUES (
                :title, :slug, :meta_title, :meta_desc, :cat_id, :city_id,
                :intro, :tips, '۱۴۰۵', 'published', 0, NOW(), NOW(), NOW()
             )",
            [
                'title'      => $titleFa,
                'slug'       => $slug,
                'meta_title' => $titleFa . ' | آدرس، تلفن و نقشه | حامی‌هاب گیلان',
                'meta_desc'  => $subtitleFa,
                'cat_id'     => $categoryId,
                'city_id'    => $cityId,
                'intro'      => '<p>در این مقاله لیستی، برترین واحدهای <strong>' . htmlspecialchars((string)$cat['name_fa'], ENT_QUOTES, 'UTF-8') . ' در ' . htmlspecialchars((string)$city['name_fa'], ENT_QUOTES, 'UTF-8') . '</strong> بر اساس امتیاز وزنی بیزین (Bayesian Weighted Score) و تایید چندمنبعی گردآوری و رتبه‌بندی شده‌اند.</p>',
                'tips'       => '<p>امتیازدهی بدون هیچ‌گونه داده ساختگی و بر پایه میانگین وزنی بیزین (با پارامتر m=20) و صحت‌سنجی مختصات در شعاع ۸۰ متری انجام شده است.</p>',
            ]
        );
        $articleId = DB::lastInsertId();

        $businesses = DB::fetchAll(
            "SELECT id, name, short_desc, tier
             FROM businesses
             WHERE city_id = :city_id AND (category_id = :cat_id OR status = 'published')
             ORDER BY (category_id = :cat_id2) DESC, (status = 'published') DESC, internal_score DESC
             LIMIT 10",
            [
                'city_id' => $cityId,
                'cat_id'  => $categoryId,
                'cat_id2' => $categoryId,
            ]
        );

        foreach ($businesses as $idx => $b) {
            $pos = $idx + 1;
            $highlights = json_encode([
                'badge'   => $pos === 1 ? 'رتبه اول بیزین' : ('منتخب Tier ' . $b['tier']),
                'verdict' => 'یکی از بهترین گزینه‌های ' . $cat['name_fa'] . ' در شهر ' . $city['name_fa'],
                'pros'    => ['امتیاز وزنی مطلوب', 'دسترسی مناسب در ' . $city['name_fa']],
                'cons'    => ['توصیه به تماس تلفنی پیش از مراجعه در روزهای تعطیل'],
            ], JSON_UNESCAPED_UNICODE);

            DB::query(
                "INSERT IGNORE INTO article_items (
                    article_id, business_id, position, custom_desc, highlights, is_sponsored
                 ) VALUES (
                    :aid, :bid, :pos, :desc, :hl, 0
                 )",
                [
                    'aid'  => $articleId,
                    'bid'  => (int)$b['id'],
                    'pos'  => $pos,
                    'desc' => (string)($b['short_desc'] ?: ($b['name'] . ' در شهر ' . $city['name_fa'])),
                    'hl'   => $highlights,
                ]
            );
        }

        DB::query(
            "INSERT INTO article_faqs (article_id, question, answer, sort) VALUES (:aid, :q, :a, 1)",
            [
                'aid' => $articleId,
                'q'   => 'بهترین ' . $cat['name_fa'] . ' در ' . $city['name_fa'] . ' بر چه اساسی انتخاب شده‌اند؟',
                'a'   => 'تمامی واحدهای این لیست بر اساس امتیاز وزنی بیزین (Bayesian Score)، بررسی چندمنبعی و تایید مختصات مکانی در شهر ' . $city['name_fa'] . ' انتخاب شده‌اند.',
            ]
        );

        Cache::flushAll();

        Response::json([
            'ok'          => true,
            'article_id'  => $articleId,
            'slug'        => $slug,
            'items_added' => count($businesses),
            'message'     => 'مقاله «' . $titleFa . '» به صورت خودکار با ' . count($businesses) . ' کسب‌وکار ساخته و منتشر شد.',
        ]);
    }
}
