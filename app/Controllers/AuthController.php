<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * کنترلر احراز هویت و عملیات سیستمی پنل مدیریت (/api/v1/auth/* و /api/v1/system/*)
 */
final class AuthController
{
    /**
     * بررسی وضعیت نشست فعلی و دریافت توکن CSRF
     * GET /api/v1/auth/me
     */
    public static function me(Request $request): void
    {
        Auth::startSessionIfNeeded();
        $user = Auth::user();

        Response::json([
            'ok' => true,
            'authenticated' => $user !== null,
            'user' => $user,
            'csrf_token' => Csrf::token(),
            'reserved_slugs' => Config::get('reserved_slugs', []),
        ]);
    }

    /**
     * ورود به پنل مدیریت با بررسی محدودیت ۵ تلاش در ۱۰ دقیقه
     * POST /api/v1/auth/login
     */
    public static function login(Request $request): void
    {
        Csrf::verifyOrFail($request);

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        if (trim($username) === '' || $password === '') {
            Response::json([
                'ok' => false,
                'error' => 'لطفاً نام کاربری و رمز عبور خود را وارد کنید.',
                'field' => trim($username) === '' ? 'username' : 'password',
            ], 422);
        }

        $result = Auth::attemptLogin($username, $password, $request->ip());
        if (!$result['ok']) {
            Response::json([
                'ok' => false,
                'error' => $result['error'],
            ], (int) $result['status']);
        }

        Response::json([
            'ok' => true,
            'message' => 'خوش آمدید! ورود با موفقیت انجام شد.',
            'user' => $result['user'],
            'csrf_token' => $result['csrf_token'],
        ]);
    }

    /**
     * خروج از پنل مدیریت
     * POST /api/v1/auth/logout
     */
    public static function logout(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::logout();
        Auth::startSessionIfNeeded();

        Response::json([
            'ok' => true,
            'message' => 'شما با موفقیت از حساب کاربری خارج شدید.',
            'csrf_token' => Csrf::token(),
        ]);
    }

    /**
     * پاک‌سازی دستی کش صفحات توسط ادمین
     * POST /api/v1/cache/flush
     */
    public static function flushCache(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);

        $deleted = Cache::flushAll();
        Response::json([
            'ok' => true,
            'deleted_pages' => $deleted,
            'message' => sprintf('کش صفحات عمومی با موفقیت پاک‌سازی شد (%d فایل حذف شد).', $deleted),
        ]);
    }

    /**
     * اجرای افزایشی مایگریشن‌های جدید دیتابیس از داخل پنل ادمین (مخصوص هاست اشتراکی بدون SSH)
     * POST /api/v1/system/migrate
     */
    public static function runMigrations(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);

        try {
            $result = Migrator::runIncremental();
            Response::json([
                'ok' => true,
                'migration_result' => $result,
                'message' => sprintf(
                    'بررسی دیتابیس انجام شد: %d مایگریشن جدید اعمال شد (%d مورد قبلاً نصب شده بود).',
                    $result['total_applied'],
                    count($result['skipped'])
                ),
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok' => false,
                'error' => 'خطا در اجرای مایگریشن: ' . $e->getMessage(),
            ], 500);
        }
    }
}
