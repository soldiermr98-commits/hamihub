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
 * کنترلر مدیریت دسته‌بندی‌ها و بسته‌های دسته (Category Packs) در مرحله S1
 */
final class CategoryController
{
    /**
     * فهرست تمام دسته‌ها و بسته‌های دسته آماده (Category Packs)
     * GET /api/v1/categories
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $categories = DB::fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM businesses b WHERE b.category_id = c.id) AS businesses_count
             FROM categories c
             ORDER BY c.sort ASC, c.id ASC"
        );

        $packs = DB::fetchAll("SELECT * FROM category_packs ORDER BY id ASC");
        $packItems = DB::fetchAll("SELECT * FROM category_pack_items ORDER BY pack_id ASC, id ASC");

        Response::json([
            'ok' => true,
            'categories' => $categories,
            'packs' => $packs,
            'pack_items' => $packItems,
        ]);
    }

    /**
     * ایجاد یا ویرایش دسته‌بندی کسب‌وکار با اعتبارسنجی اسلاگ‌های رزروشده
     * POST /api/v1/categories
     */
    public static function storeOrUpdateCategory(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $nameFa = SlugService::normalizePersianText((string) $request->input('name_fa', ''));
        $parentId = $request->input('parent_id') ? (int) $request->input('parent_id') : null;
        $sort = (int) $request->input('sort', 10);
        $isActive = (int) $request->input('is_active', 1) === 1 ? 1 : 0;

        if ($nameFa === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً نام فارسی دسته‌بندی را وارد کنید (مثلاً: کافه و رستوران).',
                'field' => 'name_fa',
            ], 422);
        }

        $rawSlug = trim((string) $request->input('slug', ''));
        if ($rawSlug === '') {
            $rawSlug = SlugService::suggestSlug($nameFa, 'cat');
        }

        $slugCheck = SlugService::validateSlug($rawSlug);
        if (!$slugCheck['ok']) {
            Response::json([
                'ok' => false,
                'error' => $slugCheck['error'],
                'field' => 'slug',
            ], 422);
        }

        $uniqueSlug = SlugService::ensureUniqueSlug('categories', strtolower($rawSlug), $id > 0 ? $id : null);

        if ($id > 0) {
            DB::query(
                "UPDATE categories
                 SET parent_id = :pid, name_fa = :name_fa, slug = :slug, sort = :sort, is_active = :active
                 WHERE id = :id",
                [
                    'pid' => $parentId,
                    'name_fa' => $nameFa,
                    'slug' => $uniqueSlug,
                    'sort' => $sort,
                    'active' => $isActive,
                    'id' => $id,
                ]
            );
            $catId = $id;
            $msg = "دسته‌بندی «{$nameFa}» با موفقیت به‌روزرسانی شد.";
        } else {
            DB::query(
                "INSERT INTO categories (parent_id, name_fa, slug, sort, is_active, created_at)
                 VALUES (:pid, :name_fa, :slug, :sort, :active, NOW())",
                [
                    'pid' => $parentId,
                    'name_fa' => $nameFa,
                    'slug' => $uniqueSlug,
                    'sort' => $sort,
                    'active' => $isActive,
                ]
            );
            $catId = DB::lastInsertId();
            $msg = "دسته‌بندی جدید «{$nameFa}» با اسلاگ «{$uniqueSlug}» ساخته شد.";
        }

        Cache::flushAll();
        $category = DB::fetchOne("SELECT * FROM categories WHERE id = :id", ['id' => $catId]);

        Response::json([
            'ok' => true,
            'message' => $msg,
            'category' => $category,
        ]);
    }

    /**
     * ایجاد یا ویرایش بسته دسته (Category Pack) برای جستجوی خودکار در پرووایدرها
     * POST /api/v1/category-packs
     */
    public static function storeOrUpdatePack(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        $id = (int) $request->input('id', 0);
        $name = SlugService::normalizePersianText((string) $request->input('name', ''));
        $categoryId = (int) $request->input('category_id', 1);
        $queriesInput = $request->input('queries_json', '');
        $isActive = (int) $request->input('is_active', 1) === 1 ? 1 : 0;

        if ($name === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً عنوان بسته دسته را وارد کنید (مثلاً: بسته کافه‌ها و رستوران‌ها).',
                'field' => 'name',
            ], 422);
        }

        if (is_array($queriesInput)) {
            $queriesJson = json_encode($queriesInput, JSON_UNESCAPED_UNICODE);
        } else {
            $decoded = json_decode((string) $queriesInput, true);
            if (!is_array($decoded)) {
                Response::json([
                    'ok' => false,
                    'error' => 'ساختار عبارات جستجو (JSON) معتبر نیست. لطفاً عبارات هر پرووایدر را بررسی کنید.',
                    'field' => 'queries_json',
                ], 422);
            }
            $queriesJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        if ($id > 0) {
            DB::query(
                "UPDATE category_packs SET name = :name, queries_json = :q, is_active = :active WHERE id = :id",
                ['name' => $name, 'q' => $queriesJson, 'active' => $isActive, 'id' => $id]
            );
            $packId = $id;
            $msg = "بسته دسته «{$name}» با موفقیت ویرایش شد.";
        } else {
            DB::query(
                "INSERT INTO category_packs (name, queries_json, is_active, created_at)
                 VALUES (:name, :q, :active, NOW())",
                ['name' => $name, 'q' => $queriesJson, 'active' => $isActive]
            );
            $packId = DB::lastInsertId();
            $msg = "بسته دسته جدید «{$name}» با موفقیت ثبت شد.";
        }

        if ($categoryId > 0) {
            DB::query(
                "INSERT INTO category_pack_items (pack_id, category_id, queries_json)
                 VALUES (:pid, :cid, :q)
                 ON DUPLICATE KEY UPDATE queries_json = VALUES(queries_json)",
                ['pid' => $packId, 'cid' => $categoryId, 'q' => $queriesJson]
            );
        }

        $pack = DB::fetchOne("SELECT * FROM category_packs WHERE id = :id", ['id' => $packId]);

        Response::json([
            'ok' => true,
            'message' => $msg,
            'pack' => $pack,
        ]);
    }
}
