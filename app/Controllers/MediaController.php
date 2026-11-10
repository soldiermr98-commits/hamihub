<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\MediaService;
use InvalidArgumentException;
use Throwable;

/**
 * کنترلر مدیریت کتابخانه رسانه (مشاهده مسیر هاست، محل‌های مصرف، ویرایش متن جایگزین و حذف امن WebP)
 */
final class MediaController
{
    /**
     * فهرست کتابخانه رسانه، وضعیت فایل‌های فیزیکی روی هاست و راهنمای ابعاد
     * GET /api/v1/media
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        $data = MediaService::listLibrary([
            'page' => $request->query('page', '1'),
            'per_page' => $request->query('per_page', '24'),
            'usage' => $request->query('usage', 'all'),
            'q' => $request->query('q', ''),
        ]);

        Response::json([
            'ok' => true,
            'items' => $data['items'],
            'pagination' => $data['pagination'],
            'summary' => $data['summary'],
            'dimension_guides' => $data['dimension_guides'],
        ]);
    }

    /**
     * ویرایش متن جایگزین (Alt) تصویر در جدول media
     * POST /api/v1/media/update
     */
    public static function update(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);
        $input = $request->json();

        $id = (int) ($input['id'] ?? 0);
        $altText = (string) ($input['alt_text'] ?? '');

        try {
            $updated = MediaService::updateAltText($id, $altText);
            if (!$updated) {
                Response::json(['ok' => false, 'error' => 'تصویر موردنظر در دیتابیس یافت نشد.'], 404);
            }
            Cache::flushAll();
            Response::json([
                'ok' => true,
                'message' => 'متن جایگزین (Alt) تصویر ذخیره شد.',
            ]);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Media update failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'خطا در به‌روزرسانی اطلاعات تصویر.'], 500);
        }
    }

    /**
     * حذف تکی یا گروهی تصاویر از جدول media و دیسک هاست (public_html/uploads)
     * POST /api/v1/media/delete
     */
    public static function delete(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        $input = $request->json();

        $purgeOrphans = !empty($input['purge_orphans']);
        if ($purgeOrphans) {
            try {
                $orphanRes = MediaService::purgeOrphanUploadFiles();
                Response::json([
                    'ok' => true,
                    'orphan_result' => $orphanRes,
                    'message' => sprintf(
                        'پاک‌سازی فایل‌های بدون رکورد دیتابیس انجام شد (%d فایل حذف شد).',
                        $orphanRes['deleted_files']
                    ),
                ]);
            } catch (Throwable $e) {
                error_log('Media orphan purge failed: ' . $e->getMessage());
                Response::json(['ok' => false, 'error' => 'خطا در پاک‌سازی فایل‌های بدون رکورد.'], 500);
            }
        }

        $rawIds = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : []);
        if (!is_array($rawIds)) {
            Response::json(['ok' => false, 'error' => 'لیست شناسه‌های رسانه نامعتبر است.'], 422);
        }
        $detachUsages = !empty($input['detach_usages']);

        try {
            $result = MediaService::deleteMediaItems($rawIds, $detachUsages);
            if ($result['deleted_count'] === 0 && !empty($result['blocked'])) {
                Response::json([
                    'ok' => false,
                    'error' => 'تصاویر انتخاب‌شده در حال استفاده هستند؛ برای جداسازی خودکار از کسب‌وکار/مقاله گزینه «جداسازی و حذف» را فعال کنید (تصاویر متصل به بنر تبلیغاتی ابتدا باید در بخش تبلیغات جایگزین شوند).',
                    'result' => $result,
                ], 409);
            }

            Cache::flushAll();

            $msg = sprintf(
                '%d رکورد تصویر از دیتابیس و %d فایل WebP از هاست حذف شد.',
                $result['deleted_count'],
                $result['deleted_files_count']
            );
            if (!empty($result['blocked'])) {
                $msg .= sprintf(' (%d تصویر در حال استفاده نادیده گرفته شد.)', count($result['blocked']));
            }

            Response::json([
                'ok' => true,
                'result' => $result,
                'message' => $msg,
            ]);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Media delete failed: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'خطا در حذف تصویر از سرور.'], 500);
        }
    }
}
