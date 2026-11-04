<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\MediaService;
use App\Services\SlugService;
use Throwable;

/**
 * کنترلر مدیریت کسب‌وکارها و آپلود رسانه WebP (مرحله S1)
 * رعایت کامل قوانین UX بخش ۷:
 * - فرم یک‌صفحه‌ای با بخش‌های ثابت و دکمه ذخیره Sticky
 * - هیچ قانونی ذخیره را قفل نمی‌کند (چک‌لیست غیرمسدودکننده برای «بدون عکس»، «شماره تأیید نشده»، «بدون مختصات»)
 * - پیشنهاد خودکار اسلاگ لاتین و بررسی اسلاگ‌های رزروشده
 */
final class BusinessController
{
    /**
     * فهرست تمام کسب‌وکارها به همراه راه‌های ارتباطی، شهر، دسته و چک‌لیست غیرمسدودکننده
     * GET /api/v1/businesses
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $rows = DB::fetchAll(
            "SELECT b.*,
                    c.name_fa AS city_name,
                    cat.name_fa AS category_name,
                    n.name_fa AS neighborhood_name,
                    m.file_path_480 AS cover_thumb_url,
                    m.file_path_800 AS cover_medium_url
             FROM businesses b
             LEFT JOIN cities c ON c.id = b.city_id
             LEFT JOIN categories cat ON cat.id = b.category_id
             LEFT JOIN neighborhoods n ON n.id = b.neighborhood_id
             LEFT JOIN media m ON m.id = b.cover_media_id
             ORDER BY b.updated_at DESC, b.id DESC
             LIMIT 300"
        );

        $contactsRows = DB::fetchAll("SELECT * FROM business_contacts ORDER BY is_primary DESC, id ASC");
        $contactsByBiz = [];
        foreach ($contactsRows as $contact) {
            $bizId = (int) $contact['business_id'];
            $contactsByBiz[$bizId][] = $contact;
        }

        foreach ($rows as &$row) {
            $bizId = (int) $row['id'];
            $contacts = $contactsByBiz[$bizId] ?? [];
            $row['contacts'] = $contacts;
            $row['warnings'] = self::buildNonBlockingWarnings($row, $contacts);
        }
        unset($row);

        Response::json([
            'ok' => true,
            'businesses' => $rows,
        ]);
    }

    /**
     * ساخت چک‌لیست غیرمسدودکننده کیفیت داده (بخش ۷ قوانین UX: هیچ قانونی ذخیره را قفل نمی‌کند)
     */
    public static function buildNonBlockingWarnings(array $business, array $contacts): array
    {
        $warnings = [];

        if (empty($business['cover_media_id'])) {
            $warnings[] = 'بدون عکس (تصویر پیش‌فرض دسته نمایش داده می‌شود)';
        }

        $hasPhone = false;
        foreach ($contacts as $c) {
            if (in_array(($c['type'] ?? ''), ['phone', 'mobile'], true) && trim((string) ($c['value'] ?? '')) !== '') {
                $hasPhone = true;
                break;
            }
        }
        if (!$hasPhone) {
            $warnings[] = 'شماره تماس ثبت یا تأیید نشده';
        }

        if (empty($business['lat']) || empty($business['lng'])) {
            $warnings[] = 'مختصات نقشه (lat/lng) وارد نشده';
        }

        if (trim((string) ($business['address'] ?? '')) === '') {
            $warnings[] = 'آدرس دقیق متنی خالی است';
        }

        if (trim((string) ($business['short_desc'] ?? '')) === '') {
            $warnings[] = 'توضیح کوتاه خالی است (آماده تکمیل با AI)';
        }

        return $warnings;
    }

    /**
     * ذخیره یا ویرایش کسب‌وکار (بدون مسدود کردن کاربر به خاطر نبود عکس یا مختصات)
     * POST /api/v1/businesses
     */
    public static function storeOrUpdate(Request $request): void
    {
        Csrf::verifyOrFail($request);
        $user = Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $name = SlugService::normalizePersianText((string) $request->input('name', ''));
        $cityId = (int) $request->input('city_id', 0);
        $categoryId = (int) $request->input('category_id', 0);

        if ($name === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً نام کسب‌وکار را وارد کنید (مثلاً: کافه لمیز رشت).',
                'field' => 'name',
            ], 422);
        }

        if ($cityId <= 0) {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً شهر کسب‌وکار را از لیست انتخاب کنید.',
                'field' => 'city_id',
            ], 422);
        }

        if ($categoryId <= 0) {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً دسته‌بندی کسب‌وکار را انتخاب کنید.',
                'field' => 'category_id',
            ], 422);
        }

        $rawSlug = trim((string) $request->input('slug', ''));
        if ($rawSlug === '') {
            $rawSlug = SlugService::suggestSlug($name, 'biz');
        }

        $slugCheck = SlugService::validateSlug($rawSlug);
        if (!$slugCheck['ok']) {
            Response::json([
                'ok' => false,
                'error' => $slugCheck['error'],
                'field' => 'slug',
            ], 422);
        }

        $uniqueSlug = SlugService::ensureUniqueSlug('businesses', strtolower($rawSlug), $id > 0 ? $id : null);

        $neighborhoodId = $request->input('neighborhood_id') ? (int) $request->input('neighborhood_id') : null;
        $address = SlugService::normalizePersianText((string) $request->input('address', ''));
        $lat = ($request->input('lat') !== null && $request->input('lat') !== '') ? (float) $request->input('lat') : null;
        $lng = ($request->input('lng') !== null && $request->input('lng') !== '') ? (float) $request->input('lng') : null;
        $hours = SlugService::normalizePersianText((string) $request->input('hours', ''));
        $shortDesc = SlugService::normalizePersianText((string) $request->input('short_desc', ''));
        $coverMediaId = $request->input('cover_media_id') ? (int) $request->input('cover_media_id') : null;

        $requestedStatus = (string) $request->input('status', 'draft');
        if (!in_array($requestedStatus, ['draft', 'pending_review', 'published', 'hidden', 'rejected'], true)) {
            $requestedStatus = 'draft';
        }

        // اگر کاربر نقش editor دارد و وضعیت published انتخاب کرده، به pending_review هدایت شود
        if ($user['role'] === 'editor' && $requestedStatus === 'published') {
            $requestedStatus = 'pending_review';
        }

        $tier = (string) $request->input('tier', 'B');
        if (!in_array($tier, ['A', 'B', 'C'], true)) {
            $tier = 'B';
        }

        $normalizedName = preg_replace('/\s+/u', '', $name) ?: $name;

        if ($id > 0) {
            DB::query(
                "UPDATE businesses
                 SET name = :name,
                     normalized_name = :norm,
                     slug = :slug,
                     category_id = :cat_id,
                     city_id = :city_id,
                     neighborhood_id = :nid,
                     address = :addr,
                     lat = :lat,
                     lng = :lng,
                     hours = :hours,
                     short_desc = :desc,
                     cover_media_id = :media_id,
                     status = :status,
                     tier = :tier,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'name' => $name,
                    'norm' => $normalizedName,
                    'slug' => $uniqueSlug,
                    'cat_id' => $categoryId,
                    'city_id' => $cityId,
                    'nid' => $neighborhoodId,
                    'addr' => $address !== '' ? $address : null,
                    'lat' => $lat,
                    'lng' => $lng,
                    'hours' => $hours !== '' ? $hours : null,
                    'desc' => $shortDesc !== '' ? $shortDesc : null,
                    'media_id' => $coverMediaId,
                    'status' => $requestedStatus,
                    'tier' => $tier,
                    'id' => $id,
                ]
            );
            $bizId = $id;
            $msg = "کسب‌وکار «{$name}» با موفقیت به‌روزرسانی شد.";
        } else {
            DB::query(
                "INSERT INTO businesses
                    (name, normalized_name, slug, category_id, city_id, neighborhood_id, address, lat, lng, hours, short_desc, cover_media_id, status, tier, internal_score, confidence, publish_reason, created_at, updated_at)
                 VALUES
                    (:name, :norm, :slug, :cat_id, :city_id, :nid, :addr, :lat, :lng, :hours, :desc, :media_id, :status, :tier, 4.100, 'medium', 'manual_entry', NOW(), NOW())",
                [
                    'name' => $name,
                    'norm' => $normalizedName,
                    'slug' => $uniqueSlug,
                    'cat_id' => $categoryId,
                    'city_id' => $cityId,
                    'nid' => $neighborhoodId,
                    'addr' => $address !== '' ? $address : null,
                    'lat' => $lat,
                    'lng' => $lng,
                    'hours' => $hours !== '' ? $hours : null,
                    'desc' => $shortDesc !== '' ? $shortDesc : null,
                    'media_id' => $coverMediaId,
                    'status' => $requestedStatus,
                    'tier' => $tier,
                ]
            );
            $bizId = DB::lastInsertId();
            $msg = "کسب‌وکار «{$name}» با اسلاگ «/b/{$uniqueSlug}/» ذخیره شد.";
        }

        // ذخیره راه‌های ارتباطی (تلفن ثابت، موبایل، اینستاگرام، واتساپ، وب‌سایت)
        DB::query("DELETE FROM business_contacts WHERE business_id = :bid AND source = 'manual'", ['bid' => $bizId]);

        $phone = SlugService::normalizePhone((string) $request->input('phone', ''));
        $instagram = trim((string) $request->input('instagram', ''), " @/");
        $whatsapp = SlugService::normalizePhone((string) $request->input('whatsapp', ''));
        $website = trim((string) $request->input('website', ''));

        if ($phone !== '') {
            $phoneType = str_starts_with($phone, '+989') ? 'mobile' : 'phone';
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, :type, :val, 1, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'type' => $phoneType, 'val' => $phone]
            );
        }

        if ($instagram !== '') {
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, 'instagram', :val, 0, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'val' => $instagram]
            );
        }

        if ($whatsapp !== '') {
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, 'whatsapp', :val, 0, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'val' => $whatsapp]
            );
        }

        if ($website !== '') {
            DB::query(
                "INSERT INTO business_contacts (business_id, type, value, is_primary, source, confidence, last_verified_at)
                 VALUES (:bid, 'website', :val, 0, 'manual', 'high', NOW())",
                ['bid' => $bizId, 'val' => $website]
            );
        }

        // ابطال کش صفحه کسب‌وکار در صورت تغییر
        Cache::forgetPage('/b/' . $uniqueSlug);

        $savedBiz = DB::fetchOne("SELECT * FROM businesses WHERE id = :id", ['id' => $bizId]);
        $savedContacts = DB::fetchAll("SELECT * FROM business_contacts WHERE business_id = :id", ['id' => $bizId]);
        if ($savedBiz !== null) {
            $savedBiz['contacts'] = $savedContacts;
            $savedBiz['warnings'] = self::buildNonBlockingWarnings($savedBiz, $savedContacts);
        }

        Response::json([
            'ok' => true,
            'message' => $msg,
            'business' => $savedBiz,
        ]);
    }

    /**
     * آپلود تصویر و تبدیل خودکار به WebP در اندازه‌های 480 / 800 / 1200 با GD
     * POST /api/v1/media/upload
     */
    public static function uploadMedia(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        if (empty($_FILES['image'])) {
            Response::json([
                'ok' => false,
                'error' => 'هیچ فایلی برای آپلود ارسال نشده است. لطفاً یک تصویر JPG، PNG یا WebP انتخاب کنید.',
                'field' => 'cover_media_id',
            ], 422);
        }

        $altText = (string) ($_POST['alt_text'] ?? '');

        try {
            $media = MediaService::handleUpload($_FILES['image'], $altText);
            Response::json([
                'ok' => true,
                'message' => 'تصویر با موفقیت بررسی و به ۳ سایز WebP (۴۸۰، ۸۰۰ و ۱۲۰۰ پیکسل) تبدیل شد.',
                'media' => $media,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'field' => 'cover_media_id',
            ], 422);
        }
    }

    /**
     * پیشنهاد خودکار اسلاگ لاتین از طریق API
     * GET /api/v1/slug/suggest?title=...
     */
    public static function suggestSlug(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        $title = (string) ($_GET['title'] ?? '');
        $suggested = SlugService::suggestSlug($title);
        $validation = SlugService::validateSlug($suggested);

        Response::json([
            'ok' => true,
            'slug' => $suggested,
            'valid' => $validation['ok'],
        ]);
    }
}
