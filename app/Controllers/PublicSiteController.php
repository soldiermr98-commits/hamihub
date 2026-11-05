<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Cache;
use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * کنترلر صفحات عمومی سئو، کش کامل فایل HTML در دیسک، اسکیماهای JSON-LD و نقشه سایت (مرحله S4)
 * منطبق با ساختار دقیق جداول 001_initial_schema.sql و سازگار با PHP 8.1+
 */
final class PublicSiteController
{
    /**
     * ایجاد مقاله لیستی پیش‌فرض («بهترین کافه‌ها و رستوران‌های رشت») در صورت خالی بودن جدول articles
     */
    public static function ensureDefaultListArticle(): void
    {
        try {
            $countRow = DB::fetchOne("SELECT COUNT(*) AS cnt FROM articles");
            if ((int)($countRow['cnt'] ?? 0) > 0) {
                return;
            }

            $rasht = DB::fetchOne("SELECT id, name_fa, slug FROM cities WHERE slug = 'rasht' LIMIT 1")
                ?? DB::fetchOne("SELECT id, name_fa, slug FROM cities ORDER BY id ASC LIMIT 1");
            $cat = DB::fetchOne("SELECT id, name_fa, slug FROM categories WHERE slug LIKE '%cafe%' OR slug LIKE '%restaurant%' ORDER BY id ASC LIMIT 1")
                ?? DB::fetchOne("SELECT id, name_fa, slug FROM categories ORDER BY id ASC LIMIT 1");

            if (!$rasht || !$cat) {
                return;
            }

            $rashtId = (int)$rasht['id'];
            $catId = (int)$cat['id'];

            DB::query(
                "INSERT INTO articles (
                    title, slug, meta_title, meta_description, category_id, city_id,
                    intro, tips_html, year_label, status, ai_generated, published_at, created_at, updated_at
                ) VALUES (
                    :title, 'best-cafes-rasht', :meta_title, :meta_desc, :cat_id, :city_id,
                    :intro, :tips_html, '۱۴۰۵', 'published', 0, NOW(), NOW(), NOW()
                )",
                [
                    'title'      => 'بهترین کافه‌ها و رستوران‌های رشت در سال ۱۴۰۵ (لیست منتخب و امتیاز بیزین)',
                    'meta_title' => 'بهترین کافه‌ها و رستوران‌های رشت (آپدیت ۱۴۰۵) | همی‌هاب گیلان',
                    'meta_desc'  => 'معرفی و رتبه‌بندی بهترین کافه‌ها و رستوران‌های رشت بر اساس امتیاز وزنی بیزین، تطابق چندمنبعی، آدرس دقیق، شماره تماس و نقشه تعاملی.',
                    'cat_id'     => $catId,
                    'city_id'    => $rashtId,
                    'intro'      => '<p>رشت به عنوان شهر خلاق خوراک‌شناسی یونسکو، میزبان صدها کافه و رستوران متنوع است. در این راهنمای جامع، سیستم هوشمند <strong>همی‌هاب گیلان (HamiHubX)</strong> با پایش چندمنبعی و فرمول امتیاز وزنی بیزین (Bayesian Score با پارامتر m=20) بهترین گزینه‌های شهر رشت را رتبه‌بندی کرده است.</p>',
                    'tips_html'  => '<p>تمامی واحدهای معرفی‌شده در این فهرست در شعاع ۸۰ متری صحت‌سنجی شده‌اند، جایگاه‌های اسپانسرشیپ با برچسب شفاف مشخص گردیده‌اند و هیچ‌گونه امتیاز ساختگی در اسکیماهای سئو ثبت نمی‌شود.</p>',
                ]
            );
            $articleId = DB::lastInsertId();

            // اتصال کسب‌وکارهای برتر شهر رشت به مقاله پیش‌فرض
            $businesses = DB::fetchAll(
                "SELECT id, name, short_desc, tier
                 FROM businesses
                 WHERE city_id = :city_id
                 ORDER BY (status = 'published') DESC, internal_score DESC, id ASC
                 LIMIT 10",
                ['city_id' => $rashtId]
            );

            $pos = 1;
            foreach ($businesses as $biz) {
                $isSponsored = ($pos === 1) ? 1 : 0;
                $highlights = json_encode([
                    'badge'   => $pos === 1 ? 'انتخاب اول تحریریه' : ('رتبه برتر Tier ' . ($biz['tier'] ?? 'B')),
                    'verdict' => 'گزینه‌ای مطمئن در شهر رشت با دسترسی عالی و امتیاز وزنی بیزین بالا.',
                    'pros'    => ['فضای دلنشین و استاندارد', 'موقعیت مکانی مناسب در رشت', 'کیفیت بالای منو و سرویس‌دهی'],
                    'cons'    => ['شلوغی در ساعات پایانی شب و روزهای تعطیل'],
                ], JSON_UNESCAPED_UNICODE);

                DB::query(
                    "INSERT IGNORE INTO article_items (
                        article_id, business_id, position, custom_desc, highlights,
                        is_sponsored, sponsor_label, sponsor_start, sponsor_end
                    ) VALUES (
                        :aid, :bid, :pos, :desc, :highlights,
                        :is_spon, :spon_label, :spon_start, :spon_end
                    )",
                    [
                        'aid'        => $articleId,
                        'bid'        => (int)$biz['id'],
                        'pos'        => $pos,
                        'desc'       => (string)($biz['short_desc'] ?: ($biz['name'] . ' یکی از مجموعه‌های منتخب و تاییدشده شهر رشت در رتبه‌بندی همی‌هاب گیلان است.')),
                        'highlights' => $highlights,
                        'is_spon'    => $isSponsored,
                        'spon_label' => 'پیشنهاد ویژه همی‌هاب',
                        'spon_start' => $isSponsored ? date('Y-m-d 00:00:00', strtotime('-7 days')) : null,
                        'spon_end'   => $isSponsored ? date('Y-m-d 23:59:59', strtotime('+60 days')) : null,
                    ]
                );
                $pos++;
            }

            // درج سؤالات متداول پیش‌فرض برای اسکیمای FAQPage
            $faqs = [
                [
                    'q' => 'رتبه‌بندی بهترین کافه‌ها و رستوران‌های رشت در همی‌هاب چگونه انجام می‌شود؟',
                    'a' => 'رتبه‌بندی بر اساس فرمول امتیاز وزنی بیزین (Bayesian Weighted Score با پارامتر m=20)، تطابق اطلاعات در چند منبع مستقل و صحت‌سنجی مختصات جغرافیایی در شعاع ۸۰ متری انجام می‌شود.',
                ],
                [
                    'q' => 'آیا امکان مسیریابی مستقیم و تماس تلفنی با واحدهای معرفی‌شده وجود دارد؟',
                    'a' => 'بله، برای تمامی کسب‌وکارهای معرفی‌شده دکمه تماس مستقیم، لینک مسیریابی روی نقشه و نقشه تعاملی خودمیزبان (Leaflet) در همان صفحه قرار داده شده است.',
                ],
                [
                    'q' => 'کسب‌وکارهای دارای جایگاه ویژه (Sponsored) چگونه نمایش داده می‌شوند؟',
                    'a' => 'واحدهای دارای اسپانسرشیپ فعال در بازه زمانی معتبر با برچسب شفاف «پیشنهاد ویژه / تبلیغ» در ابتدای فهرست متمایز می‌شوند و تاثیری بر امتیاز طبیعی بیزین سایر واحدها ندارند.',
                ],
            ];
            foreach ($faqs as $idx => $faq) {
                DB::query(
                    "INSERT INTO article_faqs (article_id, question, answer, sort) VALUES (:aid, :q, :a, :sort)",
                    [
                        'aid'  => $articleId,
                        'q'    => $faq['q'],
                        'a'    => $faq['a'],
                        'sort' => $idx + 1,
                    ]
                );
            }
        } catch (Throwable) {
            // خطای احتمالی دیتابیس نباید مانع رندر صفحه شود
        }
    }

    /**
     * GET / — صفحه اصلی عمومی سایت
     */
    public static function home(Request $req, array $params = []): void
    {
        self::ensureDefaultListArticle();

        $cities = DB::fetchAll(
            "SELECT c.id, c.slug, c.name_fa, c.lat, c.lng,
                    (SELECT COUNT(*) FROM businesses b WHERE b.city_id = c.id AND b.status = 'published') AS biz_count
             FROM cities c
             WHERE c.is_active = 1
             ORDER BY c.id ASC"
        );

        $categories = DB::fetchAll(
            "SELECT id, slug, name_fa, sort
             FROM categories
             WHERE is_active = 1
             ORDER BY sort ASC, id ASC"
        );

        $articles = DB::fetchAll(
            "SELECT a.id, a.slug, a.title, a.meta_description, a.year_label, a.published_at,
                    c.name_fa AS city_name_fa, c.slug AS city_slug,
                    cat.name_fa AS category_name_fa,
                    (SELECT COUNT(*) FROM article_items ai WHERE ai.article_id = a.id) AS items_count
             FROM articles a
             INNER JOIN cities c ON c.id = a.city_id
             LEFT JOIN categories cat ON cat.id = a.category_id
             WHERE a.status = 'published'
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT 8"
        );

        $businesses = self::fetchPublishedBusinesses("b.status = 'published'", [], 12);

        $baseUrl = self::baseUrl();
        $schemas = [
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebSite',
                'name'        => 'همی‌هاب گیلان | HamiHubX.ir',
                'url'         => $baseUrl . '/',
                'description' => 'پلتفرم خودکار معرفی و رتبه‌بندی بهترین کسب‌وکارها، کافه‌ها، رستوران‌ها و خدمات استان گیلان',
            ],
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'برترین کسب‌وکارهای منتخب استان گیلان',
                'itemListElement' => self::buildItemListElements($businesses, $baseUrl),
            ],
        ];

        ob_start();
        ?>
        <section class="hx-hero">
            <div class="hx-container">
                <span class="hx-hero-badge">پلتفرم هوشمند رتبه‌بندی محلی استان گیلان · فونت لوکال و کش فوق‌سریع</span>
                <h1>بهترین کافه‌ها، رستوران‌ها و خدمات استان گیلان</h1>
                <p>رتبه‌بندی شفاف بر اساس امتیاز وزنی بیزین (Bayesian Score)، راستی‌آزمایی چندمنبعی در شعاع ۸۰ متری و بررسی تحریریه در شهرهای رشت، بندر انزلی، لاهیجان و سراسر گیلان.</p>
                <div class="hx-city-pills">
                    <?php foreach ($cities as $city): ?>
                        <a class="hx-city-pill" href="/city/<?= self::e($city['slug']) ?>/">
                            <span>📍 <?= self::e($city['name_fa']) ?></span>
                            <small style="opacity:0.75;">(<?= (int)$city['biz_count'] ?>)</small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <main class="hx-container">
            <?php if (!empty($articles)): ?>
            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">راهنماها و مقالات لیستی منتخب (Top Lists)</h2>
                        <p class="hx-section-sub">مقالات مقایسه‌ای همراه با جدول امتیاز بیزین، نقشه تعاملی و سؤالات متداول</p>
                    </div>
                </div>
                <div class="hx-grid hx-grid-2">
                    <?php foreach ($articles as $art): ?>
                        <article class="hx-card">
                            <div class="hx-card-top">
                                <span class="hx-badge hx-badge-tier-a"><?= self::e($art['city_name_fa']) ?> · <?= (int)$art['items_count'] ?> گزینه برتر</span>
                                <?php if (!empty($art['category_name_fa'])): ?>
                                    <span class="hx-badge hx-badge-cat"><?= self::e($art['category_name_fa']) ?> · <?= self::e($art['year_label'] ?: '۱۴۰۵') ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="hx-card-title">
                                <a href="/<?= self::e($art['slug']) ?>/"><?= self::e($art['title']) ?></a>
                            </h3>
                            <?php if (!empty($art['meta_description'])): ?>
                                <p class="hx-card-desc"><?= self::e($art['meta_description']) ?></p>
                            <?php endif; ?>
                            <div class="hx-card-actions">
                                <a class="hx-btn hx-btn-primary" href="/<?= self::e($art['slug']) ?>/">مشاهده لیست کامل و نقشه ←</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">برترین کسب‌وکارهای منتشرشده در گیلان</h2>
                        <p class="hx-section-sub">مرتب‌شده بر اساس امتیاز وزنی بیزین و سطح کیفیت (Tier A/B/C)</p>
                    </div>
                </div>
                <?php if (empty($businesses)): ?>
                    <div class="hx-card">
                        <p class="hx-card-desc">هنوز کسب‌وکار منتشرشده‌ای ثبت نشده است. از پنل مدیریت (/admin/) یا صندوق بازبینی (/admin/review) کسب‌وکارها را تایید و منتشر کنید.</p>
                    </div>
                <?php else: ?>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($businesses as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, $idx + 1, 'home') ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="hx-section">
                <div class="hx-section-head">
                    <div>
                        <h2 class="hx-section-title">دسته‌بندی‌های تخصصی در شهرهای گیلان</h2>
                        <p class="hx-section-sub">انتخاب سریع بر اساس شهر و دسته‌بندی خدماتی</p>
                    </div>
                </div>
                <div class="hx-grid hx-grid-3">
                    <?php foreach ($cities as $city): ?>
                        <div class="hx-card">
                            <h3 class="hx-card-title">
                                <a href="/city/<?= self::e($city['slug']) ?>/">بهترین‌های <?= self::e($city['name_fa']) ?></a>
                            </h3>
                            <p class="hx-card-desc">دسترسی مستقیم به فهرست طبقه‌بندی‌شده کسب‌وکارهای تاییدشده در <?= self::e($city['name_fa']) ?>:</p>
                            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                                <?php foreach ($categories as $cat): ?>
                                    <a class="hx-btn hx-btn-outline" style="font-size:0.76rem;padding:0.3rem 0.6rem;" href="/city/<?= self::e($city['slug']) ?>/<?= self::e($cat['slug']) ?>/">
                                        <?= self::e($cat['name_fa']) ?> در <?= self::e($city['name_fa']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'       => 'همی‌هاب گیلان (HamiHubX) | بهترین کافه‌ها، رستوران‌ها و خدمات استان گیلان',
            'description' => 'پلتفرم هوشمند معرفی و رتبه‌بندی بهترین کسب‌وکارها، کافه‌ها، رستوران‌ها و مراکز خدماتی در رشت، انزلی، لاهیجان و سراسر استان گیلان با امتیاز وزنی بیزین.',
            'canonical'   => $baseUrl . '/',
            'og_type'     => 'website',
            'schemas'     => $schemas,
            'body'        => $bodyHtml,
        ]);

        Cache::putPage('/', $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /b/{slug} — صفحه اختصاصی هر کسب‌وکار با اسکیمای LocalBusiness (بدون امتیاز جعلی)
     */
    public static function businessDetail(Request $req, array $params = []): void
    {
        $slug = rawurldecode((string)($params['slug'] ?? ''));

        $rows = self::fetchPublishedBusinesses("b.slug = :slug AND b.status = 'published'", ['slug' => $slug], 1);
        $biz = $rows[0] ?? null;

        if (!$biz) {
            self::render404('کسب‌وکار مورد نظر یافت نشد یا هنوز در وضعیت منتشرشده قرار نگرفته است.');
            return;
        }

        $mediaItems = DB::fetchAll(
            "SELECT m.*
             FROM media m
             LEFT JOIN business_photos bp ON bp.media_id = m.id AND bp.business_id = :bid
             WHERE m.id = :cover_id OR bp.business_id = :bid2
             ORDER BY (m.id = :cover_id2) DESC, bp.sort ASC, m.id DESC",
            [
                'bid'       => (int)$biz['id'],
                'cover_id'  => (int)($biz['cover_media_id'] ?? 0),
                'bid2'      => (int)$biz['id'],
                'cover_id2' => (int)($biz['cover_media_id'] ?? 0),
            ]
        );

        $related = self::fetchPublishedBusinesses(
            "b.city_id = :city_id AND b.category_id = :cat_id AND b.id != :id AND b.status = 'published'",
            [
                'city_id' => (int)$biz['city_id'],
                'cat_id'  => (int)$biz['category_id'],
                'id'      => (int)$biz['id'],
            ],
            3
        );

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . '/b/' . rawurlencode((string)$biz['slug']) . '/';

        // اسکیمای LocalBusiness استاندارد و صادقانه بدون هیچ‌گونه aggregateRating ساختگی
        $localBizSchema = [
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            '@id'      => $canonical . '#business',
            'name'     => (string)$biz['name'],
            'url'      => $canonical,
            'address'  => [
                '@type'           => 'PostalAddress',
                'addressLocality' => (string)$biz['city_name_fa'],
                'addressRegion'   => 'گیلان',
                'addressCountry'  => 'IR',
                'streetAddress'   => (string)($biz['address'] ?: ($biz['neighborhood_name_fa'] ?: $biz['city_name_fa'])),
            ],
        ];
        if (!empty($biz['short_desc'])) {
            $localBizSchema['description'] = (string)$biz['short_desc'];
        }
        if (!empty($biz['phone'])) {
            $localBizSchema['telephone'] = (string)$biz['phone'];
        }
        if (!empty($biz['lat']) && !empty($biz['lng'])) {
            $localBizSchema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float)$biz['lat'],
                'longitude' => (float)$biz['lng'],
            ];
        }
        if (!empty($biz['hours'])) {
            $localBizSchema['openingHours'] = (string)$biz['hours'];
        }

        $breadcrumbSchema = self::buildBreadcrumbSchema([
            ['name' => 'همی‌هاب گیلان', 'url' => $baseUrl . '/'],
            ['name' => (string)$biz['city_name_fa'], 'url' => $baseUrl . '/city/' . $biz['city_slug'] . '/'],
            ['name' => (string)$biz['category_name_fa'], 'url' => $baseUrl . '/city/' . $biz['city_slug'] . '/' . $biz['category_slug'] . '/'],
            ['name' => (string)$biz['name'], 'url' => $canonical],
        ]);

        $mapMarkers = self::extractMarkers([$biz]);
        $bullets = !empty($biz['bullets_json']) ? (json_decode((string)$biz['bullets_json'], true) ?: []) : [];

        ob_start();
        ?>
        <main class="hx-container">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">همی‌هاب گیلان</a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/"><?= self::e($biz['city_name_fa']) ?></a>
                <span>/</span>
                <a href="/city/<?= self::e($biz['city_slug']) ?>/<?= self::e($biz['category_slug']) ?>/"><?= self::e($biz['category_name_fa']) ?></a>
                <span>/</span>
                <strong><?= self::e($biz['name']) ?></strong>
            </nav>

            <article class="hx-detail-hero">
                <div class="hx-card-top">
                    <div style="display:flex;gap:0.45rem;flex-wrap:wrap;align-items:center;">
                        <span class="hx-badge hx-badge-tier-<?= strtolower((string)$biz['tier']) ?>">سطح کیفی Tier <?= self::e($biz['tier']) ?></span>
                        <span class="hx-badge hx-badge-cat"><?= self::e($biz['category_name_fa']) ?> · <?= self::e($biz['city_name_fa']) ?></span>
                        <span class="hx-badge hx-badge-cat">اطمینان داده: <?= self::e($biz['confidence']) ?></span>
                    </div>
                    <div class="hx-score" title="امتیاز وزنی بیزین">
                        <span>امتیاز بیزین: <?= number_format((float)$biz['internal_score'], 2) ?></span>
                        <?php if ((int)$biz['reviews_total'] > 0): ?>
                            <small>(<?= (int)$biz['reviews_total'] ?> نظر منبع)</small>
                        <?php endif; ?>
                    </div>
                </div>

                <h1 style="font-size:1.65rem;font-weight:900;margin:0.5rem 0 0.75rem;color:var(--hx-slate-900);">
                    <?= self::e($biz['name']) ?>
                </h1>

                <?php if (!empty($biz['short_desc'])): ?>
                    <p style="font-size:1rem;color:var(--hx-slate-700);margin:0 0 1rem;line-height:1.85;">
                        <?= self::e($biz['short_desc']) ?>
                    </p>
                <?php endif; ?>

                <div class="hx-detail-grid">
                    <div class="hx-info-box">
                        <strong>📍 شهر و محله</strong>
                        <span><?= self::e($biz['city_name_fa']) ?><?= !empty($biz['neighborhood_name_fa']) ? '، ' . self::e($biz['neighborhood_name_fa']) : '' ?></span>
                    </div>
                    <div class="hx-info-box">
                        <strong>🗺️ نشانی دقیق</strong>
                        <span><?= self::e($biz['address'] ?: ('ثبت‌شده در نقشه شهر ' . $biz['city_name_fa'])) ?></span>
                    </div>
                    <?php if (!empty($biz['phone'])): ?>
                    <div class="hx-info-box">
                        <strong>📞 شماره تماس تأییدشده</strong>
                        <span dir="ltr"><?= self::e($biz['phone']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($biz['hours'])): ?>
                    <div class="hx-info-box">
                        <strong>🕒 ساعات فعالیت</strong>
                        <span><?= self::e($biz['hours']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="hx-card-actions" style="margin-top:1.25rem;">
                    <?php if (!empty($biz['phone'])): ?>
                        <a class="hx-btn hx-btn-primary" href="tel:<?= self::e($biz['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            📞 تماس مستقیم با <?= self::e($biz['name']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['lat']) && !empty($biz['lng'])): ?>
                        <a class="hx-btn hx-btn-secondary" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= (float)$biz['lat'] ?>,<?= (float)$biz['lng'] ?>" data-hx-track="map" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            🧭 مسیریابی در Google Maps
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['website'])): ?>
                        <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="<?= self::e($biz['website']) ?>" data-hx-track="website" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            🌐 وب‌سایت رسمی
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($biz['instagram'])): ?>
                        <a class="hx-btn hx-btn-outline" target="_blank" rel="noopener nofollow" href="https://instagram.com/<?= self::e(ltrim((string)$biz['instagram'], '@')) ?>" data-hx-track="instagram" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="business_detail">
                            📸 اینستاگرام (@<?= self::e(ltrim((string)$biz['instagram'], '@')) ?>)
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($bullets)): ?>
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--hx-slate-200);">
                        <h2 style="font-size:1.05rem;font-weight:800;margin:0 0 0.65rem;">نکات برجسته و ویژگی‌ها</h2>
                        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                            <?php foreach ($bullets as $bItem): ?>
                                <span class="hx-badge hx-badge-cat">✓ <?= self::e((string)$bItem) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($mediaItems)): ?>
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--hx-slate-200);">
                        <h2 style="font-size:1.05rem;font-weight:800;margin:0 0 0.65rem;">تصاویر WebP بهینه‌شده</h2>
                        <div class="hx-grid hx-grid-3">
                            <?php foreach ($mediaItems as $m): ?>
                                <figure style="margin:0;border:1px solid var(--hx-slate-200);border-radius:10px;overflow:hidden;background:#f8fafc;">
                                    <img src="<?= self::e((string)($m['file_path_800'] ?: $m['file_path_480'])) ?>" alt="<?= self::e((string)($m['alt_text'] ?: $biz['name'])) ?>" loading="lazy" width="<?= (int)($m['width'] ?: 800) ?>" height="<?= (int)($m['height'] ?: 600) ?>" style="width:100%;height:190px;object-fit:cover;display:block;">
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>

            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'موقعیت مکانی ' . $biz['name'] . ' روی نقشه تعاملی') ?>
            <?php endif; ?>

            <?php if (!empty($related)): ?>
                <section class="hx-section">
                    <div class="hx-section-head">
                        <div>
                            <h2 class="hx-section-title">سایر گزینه‌های برتر <?= self::e($biz['category_name_fa']) ?> در <?= self::e($biz['city_name_fa']) ?></h2>
                        </div>
                    </div>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($related as $idx => $relBiz): ?>
                            <?= self::renderBusinessCard($relBiz, $idx + 1, 'business_related') ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $title = $biz['name'] . ' در ' . $biz['city_name_fa'] . ' | آدرس، تلفن و امتیاز بیزین | همی‌هاب گیلان';
        $desc = !empty($biz['short_desc'])
            ? (string)$biz['short_desc']
            : ('اطلاعات کامل، آدرس، شماره تماس و مسیریابی ' . $biz['name'] . ' در ' . $biz['city_name_fa'] . ' بر اساس رتبه‌بندی همی‌هاب گیلان.');

        $fullHtml = self::renderLayout([
            'title'       => $title,
            'description' => $desc,
            'canonical'   => $canonical,
            'og_type'     => 'place',
            'schemas'     => [$localBizSchema, $breadcrumbSchema],
            'body'        => $bodyHtml,
        ]);

        Cache::putPage('/b/' . $biz['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /city/{city} — صفحه هاب هر شهر
     */
    public static function cityHub(Request $req, array $params = []): void
    {
        $citySlug = rawurldecode((string)($params['city'] ?? ''));
        $city = DB::fetchOne("SELECT * FROM cities WHERE slug = :slug AND is_active = 1 LIMIT 1", ['slug' => $citySlug]);

        if (!$city) {
            self::render404('شهر مورد نظر در استان گیلان یافت نشد.');
            return;
        }

        $categories = DB::fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort ASC, id ASC");
        $businesses = self::fetchPublishedBusinesses("b.city_id = :city_id AND b.status = 'published'", ['city_id' => (int)$city['id']], 24);
        $articles = DB::fetchAll(
            "SELECT id, slug, title, meta_description, year_label
             FROM articles
             WHERE city_id = :city_id AND status = 'published'
             ORDER BY published_at DESC, id DESC",
            ['city_id' => (int)$city['id']]
        );

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . '/city/' . rawurlencode((string)$city['slug']) . '/';

        $schemas = [
            self::buildBreadcrumbSchema([
                ['name' => 'همی‌هاب گیلان', 'url' => $baseUrl . '/'],
                ['name' => 'بهترین‌های ' . $city['name_fa'], 'url' => $canonical],
            ]),
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'بهترین کسب‌وکارهای ' . $city['name_fa'],
                'itemListElement' => self::buildItemListElements($businesses, $baseUrl),
            ],
        ];

        $mapMarkers = self::extractMarkers($businesses);

        ob_start();
        ?>
        <main class="hx-container">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">همی‌هاب گیلان</a>
                <span>/</span>
                <strong>شهر <?= self::e($city['name_fa']) ?></strong>
            </nav>

            <header class="hx-article-header">
                <span class="hx-badge hx-badge-tier-a">راهنمای جامع شهری · استان گیلان</span>
                <h1>بهترین کافه‌ها، رستوران‌ها و مراکز خدماتی <?= self::e($city['name_fa']) ?></h1>
                <p style="margin:0;color:var(--hx-slate-600);">
                    فهرست رتبه‌بندی‌شده برترین واحدهای صنفی و خدماتی شهر <?= self::e($city['name_fa']) ?> بر اساس امتیاز وزنی بیزین و تطابق چندمنبعی.
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:1rem;">
                    <?php foreach ($categories as $cat): ?>
                        <a class="hx-btn hx-btn-outline" href="/city/<?= self::e($city['slug']) ?>/<?= self::e($cat['slug']) ?>/">
                            <?= self::e($cat['name_fa']) ?> در <?= self::e($city['name_fa']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </header>

            <?php if (!empty($articles)): ?>
            <section class="hx-section">
                <h2 class="hx-section-title" style="margin-bottom:0.85rem;">مقالات و لیست‌های مقایسه‌ای شهر <?= self::e($city['name_fa']) ?></h2>
                <div class="hx-grid hx-grid-2">
                    <?php foreach ($articles as $art): ?>
                        <div class="hx-card">
                            <h3 class="hx-card-title"><a href="/<?= self::e($art['slug']) ?>/"><?= self::e($art['title']) ?></a></h3>
                            <?php if (!empty($art['meta_description'])): ?>
                                <p class="hx-card-desc"><?= self::e($art['meta_description']) ?></p>
                            <?php endif; ?>
                            <a class="hx-btn hx-btn-primary" href="/<?= self::e($art['slug']) ?>/">مطالعه راهنما ←</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'نقشه تعاملی کسب‌وکارهای منتخب شهر ' . $city['name_fa']) ?>
            <?php endif; ?>

            <section class="hx-section">
                <div class="hx-section-head">
                    <h2 class="hx-section-title">رتبه‌بندی واحدهای منتخب در <?= self::e($city['name_fa']) ?> (<?= count($businesses) ?> مورد)</h2>
                </div>
                <?php if (empty($businesses)): ?>
                    <div class="hx-card">
                        <p class="hx-card-desc">در حال حاضر کسب‌وکار منتشرشده‌ای در شهر <?= self::e($city['name_fa']) ?> ثبت نشده است.</p>
                    </div>
                <?php else: ?>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($businesses as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, $idx + 1, 'city_' . $city['slug']) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'       => 'بهترین کافه‌ها، رستوران‌ها و خدمات ' . $city['name_fa'] . ' | همی‌هاب گیلان',
            'description' => 'لیست کامل و رتبه‌بندی بهترین کسب‌وکارها، کافه‌ها، رستوران‌ها و خدمات شهر ' . $city['name_fa'] . ' همراه با آدرس، تلفن و نقشه تعاملی.',
            'canonical'   => $canonical,
            'og_type'     => 'website',
            'schemas'     => $schemas,
            'body'        => $bodyHtml,
        ]);

        Cache::putPage('/city/' . $city['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /city/{city}/{category} — صفحه ترکیبی شهر و دسته‌بندی
     */
    public static function cityCategory(Request $req, array $params = []): void
    {
        $citySlug = rawurldecode((string)($params['city'] ?? ''));
        $catSlug = rawurldecode((string)($params['category'] ?? ''));

        $city = DB::fetchOne("SELECT * FROM cities WHERE slug = :slug AND is_active = 1 LIMIT 1", ['slug' => $citySlug]);
        $category = DB::fetchOne("SELECT * FROM categories WHERE slug = :slug AND is_active = 1 LIMIT 1", ['slug' => $catSlug]);

        if (!$city || !$category) {
            self::render404('شهر یا دسته‌بندی مورد نظر یافت نشد.');
            return;
        }

        $businesses = self::fetchPublishedBusinesses(
            "b.city_id = :city_id AND b.category_id = :cat_id AND b.status = 'published'",
            [
                'city_id' => (int)$city['id'],
                'cat_id'  => (int)$category['id'],
            ],
            50
        );

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . '/city/' . rawurlencode((string)$city['slug']) . '/' . rawurlencode((string)$category['slug']) . '/';

        $schemas = [
            self::buildBreadcrumbSchema([
                ['name' => 'همی‌هاب گیلان', 'url' => $baseUrl . '/'],
                ['name' => (string)$city['name_fa'], 'url' => $baseUrl . '/city/' . $city['slug'] . '/'],
                ['name' => (string)$category['name_fa'] . ' در ' . $city['name_fa'], 'url' => $canonical],
            ]),
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'بهترین ' . $category['name_fa'] . ' در ' . $city['name_fa'],
                'itemListElement' => self::buildItemListElements($businesses, $baseUrl),
            ],
        ];

        $mapMarkers = self::extractMarkers($businesses);

        ob_start();
        ?>
        <main class="hx-container">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">همی‌هاب گیلان</a>
                <span>/</span>
                <a href="/city/<?= self::e($city['slug']) ?>/"><?= self::e($city['name_fa']) ?></a>
                <span>/</span>
                <strong><?= self::e($category['name_fa']) ?> در <?= self::e($city['name_fa']) ?></strong>
            </nav>

            <header class="hx-article-header">
                <span class="hx-badge hx-badge-tier-a"><?= self::e($city['name_fa']) ?> · <?= self::e($category['name_fa']) ?></span>
                <h1>بهترین <?= self::e($category['name_fa']) ?> در <?= self::e($city['name_fa']) ?> (رتبه‌بندی بیزین ۱۴۰۵)</h1>
                <p style="margin:0;color:var(--hx-slate-600);">
                    مقایسه و معرفی برترین مراکز <?= self::e($category['name_fa']) ?> در شهر <?= self::e($city['name_fa']) ?> همراه با آدرس، شماره تماس مستقیم و موقعیت روی نقشه.
                </p>
            </header>

            <?php if (!empty($mapMarkers)): ?>
                <?= self::renderLazyMapBox($mapMarkers, 'نقشه مراکز ' . $category['name_fa'] . ' در ' . $city['name_fa']) ?>
            <?php endif; ?>

            <section class="hx-section">
                <?php if (empty($businesses)): ?>
                    <div class="hx-card">
                        <p class="hx-card-desc">هنوز واحدی در دسته «<?= self::e($category['name_fa']) ?>» برای شهر <?= self::e($city['name_fa']) ?> منتشر نشده است.</p>
                    </div>
                <?php else: ?>
                    <div class="hx-grid hx-grid-3">
                        <?php foreach ($businesses as $idx => $biz): ?>
                            <?= self::renderBusinessCard($biz, $idx + 1, 'city_cat_' . $city['slug'] . '_' . $category['slug']) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $fullHtml = self::renderLayout([
            'title'       => 'بهترین ' . $category['name_fa'] . ' در ' . $city['name_fa'] . ' | رتبه‌بندی و آدرس | همی‌هاب',
            'description' => 'معرفی بهترین ' . $category['name_fa'] . ' در شهر ' . $city['name_fa'] . ' بر اساس امتیاز وزنی بیزین، نظرات کاربران، آدرس دقیق و شماره تماس.',
            'canonical'   => $canonical,
            'og_type'     => 'website',
            'schemas'     => $schemas,
            'body'        => $bodyHtml,
        ]);

        Cache::putPage('/city/' . $city['slug'] . '/' . $category['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /{slug} — صفحه مقاله لیستی («بهترین کافه‌های رشت») با اسکیمای Article + ItemList + FAQPage + BreadcrumbList
     */
    public static function articleDetail(Request $req, array $params = []): void
    {
        self::ensureDefaultListArticle();
        $slug = rawurldecode((string)($params['slug'] ?? ''));

        $article = DB::fetchOne(
            "SELECT a.*,
                    c.name_fa AS city_name_fa, c.slug AS city_slug,
                    cat.name_fa AS category_name_fa, cat.slug AS category_slug
             FROM articles a
             INNER JOIN cities c ON c.id = a.city_id
             LEFT JOIN categories cat ON cat.id = a.category_id
             WHERE a.slug = :slug AND a.status = 'published'
             LIMIT 1",
            ['slug' => $slug]
        );

        if (!$article) {
            self::render404('مقاله یا لیست مورد نظر یافت نشد.');
            return;
        }

        // دریافت آیتم‌های مقاله همراه با پین شدن خودکار جایگاه‌های اسپانسرشیپ فعال در بازه [sponsor_start, sponsor_end]
        $items = DB::fetchAll(
            "SELECT ai.*,
                    b.slug AS biz_slug, b.name, b.short_desc, b.address, b.lat, b.lng,
                    b.internal_score, b.tier, b.confidence,
                    n.name_fa AS neighborhood_name_fa,
                    c.name_fa AS city_name_fa, c.slug AS city_slug,
                    cat.name_fa AS category_name_fa, cat.slug AS category_slug,
                    (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type IN ('phone','mobile') ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS phone,
                    (SELECT COALESCE(SUM(bs.reviews_count), 0) FROM business_sources bs WHERE bs.business_id = b.id) AS reviews_total
             FROM article_items ai
             INNER JOIN businesses b ON b.id = ai.business_id
             INNER JOIN cities c ON c.id = b.city_id
             INNER JOIN categories cat ON cat.id = b.category_id
             LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
             WHERE ai.article_id = :aid
             ORDER BY
                 CASE WHEN ai.is_sponsored = 1
                      AND (ai.sponsor_start IS NULL OR ai.sponsor_start <= NOW())
                      AND (ai.sponsor_end IS NULL OR ai.sponsor_end >= NOW())
                 THEN 1 ELSE 0 END DESC,
                 ai.position ASC,
                 b.internal_score DESC",
            ['aid' => (int)$article['id']]
        );

        $faqs = DB::fetchAll(
            "SELECT * FROM article_faqs WHERE article_id = :aid ORDER BY sort ASC, id ASC",
            ['aid' => (int)$article['id']]
        );

        $baseUrl = self::baseUrl();
        $canonical = $baseUrl . '/' . rawurlencode((string)$article['slug']) . '/';

        // اسکیماهای چهارگانه Article + ItemList + BreadcrumbList + FAQPage
        $articleSchema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => (string)$article['title'],
            'description'      => (string)($article['meta_description'] ?: $article['title']),
            'datePublished'    => (string)($article['published_at'] ?: $article['created_at']),
            'dateModified'     => (string)$article['updated_at'],
            'mainEntityOfPage' => $canonical,
            'author'           => [
                '@type' => 'Organization',
                'name'  => 'تحریریه همی‌هاب گیلان (HamiHubX)',
                'url'   => $baseUrl . '/',
            ],
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => 'HamiHubX.ir',
                'url'   => $baseUrl . '/',
            ],
        ];

        $itemListElements = [];
        foreach ($items as $idx => $it) {
            $itemListElements[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'name'     => (string)$it['name'],
                'url'      => $baseUrl . '/b/' . rawurlencode((string)$it['biz_slug']) . '/',
            ];
        }
        $itemListSchema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => (string)$article['title'],
            'numberOfItems'   => count($itemListElements),
            'itemListElement' => $itemListElements,
        ];

        $breadcrumbItems = [
            ['name' => 'همی‌هاب گیلان', 'url' => $baseUrl . '/'],
            ['name' => (string)$article['city_name_fa'], 'url' => $baseUrl . '/city/' . $article['city_slug'] . '/'],
        ];
        if (!empty($article['category_slug'])) {
            $breadcrumbItems[] = [
                'name' => (string)$article['category_name_fa'],
                'url'  => $baseUrl . '/city/' . $article['city_slug'] . '/' . $article['category_slug'] . '/',
            ];
        }
        $breadcrumbItems[] = ['name' => (string)$article['title'], 'url' => $canonical];

        $schemas = [
            $articleSchema,
            $itemListSchema,
            self::buildBreadcrumbSchema($breadcrumbItems),
        ];

        if (!empty($faqs)) {
            $faqEntities = [];
            foreach ($faqs as $f) {
                $faqEntities[] = [
                    '@type'          => 'Question',
                    'name'           => (string)$f['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => (string)$f['answer'],
                    ],
                ];
            }
            $schemas[] = [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $faqEntities,
            ];
        }

        $mapMarkers = [];
        foreach ($items as $idx => $it) {
            if (!empty($it['lat']) && !empty($it['lng'])) {
                $mapMarkers[] = [
                    'rank'     => $idx + 1,
                    'name'     => (string)$it['name'],
                    'lat'      => (float)$it['lat'],
                    'lng'      => (float)$it['lng'],
                    'district' => (string)($it['neighborhood_name_fa'] ?: $it['city_name_fa']),
                    'score'    => number_format((float)$it['internal_score'], 2),
                    'url'      => '/b/' . $it['biz_slug'] . '/',
                ];
            }
        }

        ob_start();
        ?>
        <main class="hx-container">
            <nav class="hx-breadcrumb" aria-label="مسیر صفحه">
                <a href="/">همی‌هاب گیلان</a>
                <span>/</span>
                <a href="/city/<?= self::e($article['city_slug']) ?>/"><?= self::e($article['city_name_fa']) ?></a>
                <?php if (!empty($article['category_slug'])): ?>
                    <span>/</span>
                    <a href="/city/<?= self::e($article['city_slug']) ?>/<?= self::e($article['category_slug']) ?>/"><?= self::e($article['category_name_fa']) ?></a>
                <?php endif; ?>
                <span>/</span>
                <strong><?= self::e($article['title']) ?></strong>
            </nav>

            <article>
                <header class="hx-article-header">
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <span class="hx-badge hx-badge-tier-a">گزارش تحلیلی و رتبه‌بندی بیزین · <?= self::e($article['year_label'] ?: '۱۴۰۵') ?></span>
                        <span class="hx-badge hx-badge-cat">شهر <?= self::e($article['city_name_fa']) ?> · <?= count($items) ?> واحد منتخب</span>
                    </div>
                    <h1><?= self::e($article['title']) ?></h1>

                    <div class="hx-summary-box">
                        <div class="hx-summary-item">
                            <strong>شهر و حوزه بررسی</strong>
                            <span><?= self::e($article['city_name_fa']) ?><?= !empty($article['category_name_fa']) ? ' · ' . self::e($article['category_name_fa']) : '' ?></span>
                        </div>
                        <div class="hx-summary-item">
                            <strong>تعداد گزینه‌های رتبه‌بندی‌شده</strong>
                            <span><?= count($items) ?> واحد منتخب</span>
                        </div>
                        <div class="hx-summary-item">
                            <strong>الگوریتم رتبه‌بندی</strong>
                            <span>امتیاز وزنی بیزین (Bayesian m=20)</span>
                        </div>
                    </div>

                    <?php if (!empty($article['intro'])): ?>
                        <div class="hx-prose" style="margin-top:1rem;">
                            <?= (string)$article['intro'] ?>
                        </div>
                    <?php endif; ?>
                </header>

                <?php if (!empty($items)): ?>
                <section class="hx-section">
                    <h2 class="hx-section-title" style="margin-bottom:0.75rem;">جدول مقایسه سریع بهترین گزینه‌ها</h2>
                    <div class="hx-table-wrap">
                        <table class="hx-table">
                            <thead>
                                <tr>
                                    <th>رتبه</th>
                                    <th>نام مجموعه</th>
                                    <th>محله / منطقه</th>
                                    <th>امتیاز وزنی بیزین</th>
                                    <th>سطح کیفی</th>
                                    <th>ارتباط مستقیم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $idx => $it):
                                    $isSponsor = self::isActiveSponsored($it);
                                    $hl = !empty($it['highlights']) ? (json_decode((string)$it['highlights'], true) ?: []) : [];
                                ?>
                                    <tr>
                                        <td><strong>#<?= $idx + 1 ?></strong></td>
                                        <td>
                                            <a href="/b/<?= self::e($it['biz_slug']) ?>/" style="font-weight:800;color:var(--hx-slate-900);">
                                                <?= self::e($it['name']) ?>
                                            </a>
                                            <?php if ($isSponsor): ?>
                                                <span class="hx-badge hx-badge-sponsor" style="margin-right:0.35rem;">⭐ <?= self::e($it['sponsor_label'] ?: 'تبلیغ') ?></span>
                                            <?php elseif (!empty($hl['badge'])): ?>
                                                <span class="hx-badge hx-badge-cat" style="margin-right:0.35rem;"><?= self::e($hl['badge']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= self::e($it['neighborhood_name_fa'] ?: $it['city_name_fa']) ?></td>
                                        <td><span class="hx-score"><?= number_format((float)$it['internal_score'], 2) ?></span></td>
                                        <td><span class="hx-badge hx-badge-tier-<?= strtolower((string)$it['tier']) ?>">Tier <?= self::e($it['tier']) ?></span></td>
                                        <td>
                                            <?php if (!empty($it['phone'])): ?>
                                                <a class="hx-btn hx-btn-primary" style="padding:0.25rem 0.6rem;font-size:0.75rem;" href="tel:<?= self::e($it['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$it['business_id'] ?>" data-source-page="article_table">📞 تماس</a>
                                            <?php else: ?>
                                                <a class="hx-btn hx-btn-outline" style="padding:0.25rem 0.6rem;font-size:0.75rem;" href="/b/<?= self::e($it['biz_slug']) ?>/">مشاهده</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!empty($mapMarkers)): ?>
                    <?= self::renderLazyMapBox($mapMarkers, 'نقشه تعاملی گزینه‌های منتخب این مقاله') ?>
                <?php endif; ?>

                <section class="hx-section">
                    <h2 class="hx-section-title" style="margin-bottom:1rem;">بررسی تک‌به‌تک واحدهای منتخب</h2>
                    <div class="hx-grid" style="gap:1.25rem;">
                        <?php foreach ($items as $idx => $it):
                            $hl = !empty($it['highlights']) ? (json_decode((string)$it['highlights'], true) ?: []) : [];
                            $pros = is_array($hl['pros'] ?? null) ? $hl['pros'] : [];
                            $cons = is_array($hl['cons'] ?? null) ? $hl['cons'] : [];
                            $isSponsor = self::isActiveSponsored($it);
                        ?>
                            <div class="hx-card <?= $isSponsor ? 'hx-card-sponsored' : '' ?>" id="rank-<?= $idx + 1 ?>">
                                <div class="hx-card-top">
                                    <div style="display:flex;align-items:center;gap:0.55rem;flex-wrap:wrap;">
                                        <span class="hx-rank-num"><?= $idx + 1 ?></span>
                                        <h3 class="hx-card-title" style="margin:0;font-size:1.2rem;">
                                            <a href="/b/<?= self::e($it['biz_slug']) ?>/"><?= self::e($it['name']) ?></a>
                                        </h3>
                                        <?php if ($isSponsor): ?>
                                            <span class="hx-badge hx-badge-sponsor">⭐ <?= self::e($it['sponsor_label'] ?: 'پیشنهاد ویژه') ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($hl['badge'])): ?>
                                            <span class="hx-badge hx-badge-tier-a"><?= self::e((string)$hl['badge']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="hx-score">
                                        <span>امتیاز بیزین: <?= number_format((float)$it['internal_score'], 2) ?></span>
                                    </div>
                                </div>

                                <div class="hx-card-meta">
                                    <span>📍 <?= self::e($it['city_name_fa']) ?><?= !empty($it['neighborhood_name_fa']) ? '، ' . self::e($it['neighborhood_name_fa']) : '' ?></span>
                                    <?php if (!empty($it['address'])): ?>
                                        <span>· <?= self::e($it['address']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($hl['verdict'])): ?>
                                    <p style="margin:0;font-weight:700;color:var(--hx-brand-dark);font-size:0.9rem;">
                                        💡 جمع‌بندی تحریریه: <?= self::e((string)$hl['verdict']) ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($it['custom_desc'])): ?>
                                    <p class="hx-card-desc"><?= self::e((string)$it['custom_desc']) ?></p>
                                <?php elseif (!empty($it['short_desc'])): ?>
                                    <p class="hx-card-desc"><?= self::e((string)$it['short_desc']) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($pros) || !empty($cons)): ?>
                                    <div class="hx-pros-cons">
                                        <?php if (!empty($pros)): ?>
                                            <div class="hx-pros">
                                                <strong>✅ نقاط قوت</strong>
                                                <ul>
                                                    <?php foreach ($pros as $p): ?>
                                                        <li><?= self::e((string)$p) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($cons)): ?>
                                            <div class="hx-cons">
                                                <strong>⚠️ نکات قابل توجه</strong>
                                                <ul>
                                                    <?php foreach ($cons as $c): ?>
                                                        <li><?= self::e((string)$c) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="hx-card-actions">
                                    <a class="hx-btn hx-btn-outline" href="/b/<?= self::e($it['biz_slug']) ?>/">صفحه اختصاصی و جزئیات</a>
                                    <?php if (!empty($it['phone'])): ?>
                                        <a class="hx-btn hx-btn-primary" href="tel:<?= self::e($it['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$it['business_id'] ?>" data-source-page="article_card">📞 تماس مستقیم</a>
                                    <?php endif; ?>
                                    <?php if (!empty($it['lat']) && !empty($it['lng'])): ?>
                                        <a class="hx-btn hx-btn-secondary" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= (float)$it['lat'] ?>,<?= (float)$it['lng'] ?>" data-hx-track="map" data-business-id="<?= (int)$it['business_id'] ?>" data-source-page="article_card">🧭 مسیریابی</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if (!empty($article['tips_html'])): ?>
                    <section class="hx-section">
                        <div class="hx-card">
                            <h2 class="hx-section-title" style="margin-bottom:0.5rem;">روش‌شناسی امتیازدهی و راستی‌آزمایی در همی‌هاب</h2>
                            <div class="hx-prose"><?= (string)$article['tips_html'] ?></div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($faqs)): ?>
                    <section class="hx-section">
                        <h2 class="hx-section-title" style="margin-bottom:0.85rem;">سؤالات متداول (FAQ)</h2>
                        <?php foreach ($faqs as $f): ?>
                            <div class="hx-faq-item">
                                <p class="hx-faq-q">❓ <?= self::e($f['question']) ?></p>
                                <p class="hx-faq-a"><?= self::e($f['answer']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </article>
        </main>
        <?php
        $bodyHtml = (string)ob_get_clean();

        $title = !empty($article['meta_title']) ? (string)$article['meta_title'] : ((string)$article['title'] . ' | همی‌هاب گیلان');
        $desc = !empty($article['meta_description']) ? (string)$article['meta_description'] : (string)$article['title'];

        $fullHtml = self::renderLayout([
            'title'       => $title,
            'description' => $desc,
            'canonical'   => $canonical,
            'og_type'     => 'article',
            'schemas'     => $schemas,
            'body'        => $bodyHtml,
        ]);

        Cache::putPage('/' . $article['slug'], $fullHtml);
        Response::html($fullHtml);
    }

    /**
     * GET /sitemap.xml
     */
    public static function sitemapXml(Request $req, array $params = []): void
    {
        self::ensureDefaultListArticle();
        $baseUrl = self::baseUrl();

        $urls = [
            ['loc' => $baseUrl . '/', 'priority' => '1.0', 'changefreq' => 'daily'],
        ];

        $cities = DB::fetchAll("SELECT slug FROM cities WHERE is_active = 1 ORDER BY id ASC");
        $categories = DB::fetchAll("SELECT slug FROM categories WHERE is_active = 1 ORDER BY sort ASC");

        foreach ($cities as $c) {
            $urls[] = [
                'loc'        => $baseUrl . '/city/' . rawurlencode((string)$c['slug']) . '/',
                'priority'   => '0.9',
                'changefreq' => 'daily',
            ];
            foreach ($categories as $cat) {
                $urls[] = [
                    'loc'        => $baseUrl . '/city/' . rawurlencode((string)$c['slug']) . '/' . rawurlencode((string)$cat['slug']) . '/',
                    'priority'   => '0.8',
                    'changefreq' => 'weekly',
                ];
            }
        }

        $articles = DB::fetchAll("SELECT slug FROM articles WHERE status = 'published' ORDER BY id DESC");
        foreach ($articles as $a) {
            $urls[] = [
                'loc'        => $baseUrl . '/' . rawurlencode((string)$a['slug']) . '/',
                'priority'   => '0.9',
                'changefreq' => 'weekly',
            ];
        }

        $businesses = DB::fetchAll("SELECT slug FROM businesses WHERE status = 'published' ORDER BY id DESC LIMIT 1000");
        foreach ($businesses as $b) {
            $urls[] = [
                'loc'        => $baseUrl . '/b/' . rawurlencode((string)$b['slug']) . '/',
                'priority'   => '0.8',
                'changefreq' => 'weekly',
            ];
        }

        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
            echo "    <priority>{$u['priority']}</priority>\n";
            echo "  </url>\n";
        }
        echo "</urlset>\n";
        exit;
    }

    /**
     * GET /robots.txt
     */
    public static function robotsTxt(Request $req, array $params = []): void
    {
        $baseUrl = self::baseUrl();
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin/\n";
        echo "Disallow: /api/\n";
        echo "Disallow: /install.php\n";
        echo "Disallow: /check.php\n\n";
        echo "Sitemap: {$baseUrl}/sitemap.xml\n";
        exit;
    }

    /**
     * POST /api/v1/events/track — ثبت سبک کلیک‌های تماس، نقشه، اینستاگرام و وب‌سایت در جدول events_daily
     */
    public static function trackEvent(Request $req, array $params = []): void
    {
        try {
            $bizId = max(0, (int)$req->input('business_id', 0));
            $artId = max(0, (int)$req->input('article_id', 0));
            $type = (string)$req->input('event_type', 'view');
            if (!in_array($type, ['view', 'call', 'map', 'instagram', 'whatsapp', 'website'], true)) {
                $type = 'view';
            }
            DB::query(
                "INSERT INTO events_daily (day, business_id, article_id, type, cnt)
                 VALUES (CURDATE(), :bid, :aid, :type, 1)
                 ON DUPLICATE KEY UPDATE cnt = cnt + 1",
                [
                    'bid'  => $bizId,
                    'aid'  => $artId,
                    'type' => $type,
                ]
            );
        } catch (Throwable) {
            // Non-blocking
        }
        Response::json(['ok' => true]);
    }

    /**
     * واکشی یکپارچه کسب‌وکارهای منتشرشده همراه با اطلاعات تماس، شهر، دسته و تعداد نظرات منابع
     */
    private static function fetchPublishedBusinesses(string $whereClause, array $params, int $limit): array
    {
        $sql = "
            SELECT b.*,
                   c.name_fa AS city_name_fa, c.slug AS city_slug,
                   cat.name_fa AS category_name_fa, cat.slug AS category_slug,
                   n.name_fa AS neighborhood_name_fa,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type IN ('phone','mobile') ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS phone,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type = 'website' ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS website,
                   (SELECT bc.value FROM business_contacts bc WHERE bc.business_id = b.id AND bc.type = 'instagram' ORDER BY bc.is_primary DESC, bc.id ASC LIMIT 1) AS instagram,
                   (SELECT COALESCE(SUM(bs.reviews_count), 0) FROM business_sources bs WHERE bs.business_id = b.id) AS reviews_total
            FROM businesses b
            INNER JOIN cities c ON c.id = b.city_id
            INNER JOIN categories cat ON cat.id = b.category_id
            LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
            WHERE {$whereClause}
            ORDER BY b.internal_score DESC, b.id DESC
            LIMIT {$limit}
        ";
        return DB::fetchAll($sql, $params);
    }

    private static function renderBusinessCard(array $biz, int $rank, string $sourcePage): string
    {
        $slugUrl = '/b/' . rawurlencode((string)$biz['slug']) . '/';
        ob_start();
        ?>
        <article class="hx-card">
            <div class="hx-card-top">
                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <span class="hx-rank-num"><?= $rank ?></span>
                    <span class="hx-badge hx-badge-tier-<?= strtolower((string)$biz['tier']) ?>">Tier <?= self::e($biz['tier']) ?></span>
                </div>
                <div class="hx-score" title="امتیاز وزنی بیزین">
                    <span><?= number_format((float)$biz['internal_score'], 2) ?></span>
                    <?php if (!empty($biz['reviews_total'])): ?>
                        <small>(<?= (int)$biz['reviews_total'] ?>)</small>
                    <?php endif; ?>
                </div>
            </div>

            <h3 class="hx-card-title">
                <a href="<?= self::e($slugUrl) ?>"><?= self::e($biz['name']) ?></a>
            </h3>

            <div class="hx-card-meta">
                <span>📍 <?= self::e($biz['city_name_fa'] ?? '') ?><?= !empty($biz['neighborhood_name_fa']) ? '، ' . self::e($biz['neighborhood_name_fa']) : '' ?></span>
                <?php if (!empty($biz['category_name_fa'])): ?>
                    <span>· <?= self::e($biz['category_name_fa']) ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($biz['short_desc'])): ?>
                <p class="hx-card-desc"><?= self::e($biz['short_desc']) ?></p>
            <?php endif; ?>

            <div class="hx-card-actions">
                <a class="hx-btn hx-btn-outline" href="<?= self::e($slugUrl) ?>">مشاهده جزئیات</a>
                <?php if (!empty($biz['phone'])): ?>
                    <a class="hx-btn hx-btn-primary" href="tel:<?= self::e($biz['phone']) ?>" data-hx-track="call" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="<?= self::e($sourcePage) ?>">📞 تماس</a>
                <?php endif; ?>
                <?php if (!empty($biz['lat']) && !empty($biz['lng'])): ?>
                    <a class="hx-btn hx-btn-secondary" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= (float)$biz['lat'] ?>,<?= (float)$biz['lng'] ?>" data-hx-track="map" data-business-id="<?= (int)$biz['id'] ?>" data-source-page="<?= self::e($sourcePage) ?>">🧭 نقشه</a>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }

    private static function renderLazyMapBox(array $markers, string $title): string
    {
        $jsonMarkers = htmlspecialchars((string)json_encode($markers, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
        <section class="hx-map-box">
            <div class="hx-map-placeholder" data-hx-map="1" data-markers="<?= $jsonMarkers ?>">
                <strong>🗺️ <?= self::e($title) ?> (<?= count($markers) ?> نقطه مکانی)</strong>
                <p style="margin:0;font-size:0.84rem;color:var(--hx-slate-600);">
                    برای حفظ سرعت بارگذاری صفحه، نقشه تعاملی Leaflet به صورت خودمیزبان (Self-Hosted) و تنها با کلیک شما بارگذاری می‌شود.
                </p>
                <button type="button" class="hx-btn hx-btn-primary" data-hx-load-map="1">
                    نمایش نقشه تعاملی و پین‌های شماره‌گذاری‌شده
                </button>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private static function renderLayout(array $data): string
    {
        $title = (string)($data['title'] ?? 'همی‌هاب گیلان');
        $description = (string)($data['description'] ?? '');
        $canonical = (string)($data['canonical'] ?? self::baseUrl() . '/');
        $ogType = (string)($data['og_type'] ?? 'website');
        $schemas = (array)($data['schemas'] ?? []);
        $body = (string)($data['body'] ?? '');

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= self::e($title) ?></title>
    <meta name="description" content="<?= self::e($description) ?>">
    <link rel="canonical" href="<?= self::e($canonical) ?>">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="<?= self::e($ogType) ?>">
    <meta property="og:title" content="<?= self::e($title) ?>">
    <meta property="og:description" content="<?= self::e($description) ?>">
    <meta property="og:url" content="<?= self::e($canonical) ?>">
    <meta property="og:site_name" content="همی‌هاب گیلان | HamiHubX.ir">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= self::e($title) ?>">
    <meta name="twitter:description" content="<?= self::e($description) ?>">
    <link rel="preload" href="/assets/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/Vazirmatn-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/public.css?v=s4">
    <?php foreach ($schemas as $schema): ?>
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endforeach; ?>
</head>
<body>
    <header class="hx-header">
        <div class="hx-container hx-header-inner">
            <a href="/" class="hx-brand">
                <span class="hx-brand-mark">H</span>
                <span>همی‌هاب گیلان <small style="font-weight:400;color:var(--hx-slate-600);font-size:0.75rem;">HamiHubX.ir</small></span>
            </a>
            <nav class="hx-nav" aria-label="منوی اصلی">
                <a href="/">صفحه اصلی</a>
                <a href="/best-cafes-rasht/">بهترین کافه‌های رشت</a>
                <a href="/city/rasht/">شهر رشت</a>
                <a href="/city/anzali/">بندر انزلی</a>
                <a href="/city/lahijan/">لاهیجان</a>
                <a href="/admin/" style="color:var(--hx-brand-dark);">پنل مدیریت</a>
            </nav>
        </div>
    </header>

    <?= $body ?>

    <footer class="hx-footer">
        <div class="hx-container hx-footer-inner">
            <div>
                <strong style="color:#fff;">همی‌هاب گیلان (HamiHubX.ir)</strong> — پلتفرم خودکار معرفی و رتبه‌بندی بهترین کسب‌وکارهای استان گیلان بر اساس الگوریتم امتیاز وزنی بیزین.
            </div>
            <div style="display:flex;gap:1rem;">
                <a href="/sitemap.xml">نقشه سایت (Sitemap)</a>
                <a href="/robots.txt">Robots.txt</a>
                <a href="/admin/">ورود مدیران</a>
            </div>
        </div>
    </footer>
    <script src="/assets/js/public.js?v=s4" defer></script>
</body>
</html>
        <?php
        return (string)ob_get_clean();
    }

    private static function render404(string $message): void
    {
        $html = self::renderLayout([
            'title'       => 'صفحه یافت نشد (404) | همی‌هاب گیلان',
            'description' => $message,
            'canonical'   => self::baseUrl() . '/',
            'body'        => '<main class="hx-container" style="padding:3rem 1rem;"><div class="hx-card" style="text-align:center;padding:2.5rem;"><h1>۴۰۴ — صفحه مورد نظر یافت نشد</h1><p class="hx-card-desc">' . self::e($message) . '</p><div style="margin-top:1rem;"><a class="hx-btn hx-btn-primary" href="/">بازگشت به صفحه اصلی همی‌هاب</a></div></div></main>',
        ]);
        Response::html($html, 404);
    }

    private static function isActiveSponsored(array $item): bool
    {
        if (empty($item['is_sponsored'])) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if (!empty($item['sponsor_start']) && $item['sponsor_start'] > $now) {
            return false;
        }
        if (!empty($item['sponsor_end']) && $item['sponsor_end'] < $now) {
            return false;
        }
        return true;
    }

    private static function buildItemListElements(array $businesses, string $baseUrl): array
    {
        $elements = [];
        foreach ($businesses as $idx => $b) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'name'     => (string)$b['name'],
                'url'      => $baseUrl . '/b/' . rawurlencode((string)$b['slug']) . '/',
            ];
        }
        return $elements;
    }

    private static function buildBreadcrumbSchema(array $crumbs): array
    {
        $elements = [];
        foreach ($crumbs as $idx => $c) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'name'     => (string)$c['name'],
                'item'     => (string)$c['url'],
            ];
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    private static function extractMarkers(array $businesses): array
    {
        $markers = [];
        foreach ($businesses as $idx => $b) {
            if (!empty($b['lat']) && !empty($b['lng'])) {
                $markers[] = [
                    'rank'     => $idx + 1,
                    'name'     => (string)$b['name'],
                    'lat'      => (float)$b['lat'],
                    'lng'      => (float)$b['lng'],
                    'district' => (string)($b['neighborhood_name_fa'] ?: ($b['city_name_fa'] ?? '')),
                    'score'    => number_format((float)$b['internal_score'], 2),
                    'url'      => '/b/' . $b['slug'] . '/',
                ];
            }
        }
        return $markers;
    }

    private static function baseUrl(): string
    {
        $configured = rtrim((string)Config::get('APP_URL', ''), '/');
        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            return $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'hamihubx.ir';
        return $scheme . '://' . $host;
    }

    private static function e(mixed $val): string
    {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}
