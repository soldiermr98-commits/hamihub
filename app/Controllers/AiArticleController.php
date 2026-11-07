<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiArticleService;
use RuntimeException;
use Throwable;

final class AiArticleController
{
    private static function schemaReady(): bool
    {
        return DB::fetchOne("SHOW TABLES LIKE 'ai_article_requests'") !== null
            && DB::fetchOne("SHOW TABLES LIKE 'ai_article_quota_lock'") !== null;
    }

    public static function status(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);
        header('Cache-Control: private, no-store');
        $service = new AiArticleService((array)Config::get('ai', []));
        Response::json(['ok'=>true, 'schema_ready'=>self::schemaReady(), 'providers'=>$service->status()]);
    }

    public static function generate(Request $request): void
    {
        $user = Auth::requireRole(['admin', 'editor']);
        Csrf::verifyOrFail($request);
        header('Cache-Control: private, no-store');
        if (!self::schemaReady()) Response::json(['ok'=>false, 'error'=>'ابتدا به‌روزرسانی اختصاصی دیتابیس را از پنل مدیر اجرا کنید.'], 409);
        if ($request->input('confirm_send') !== true || $request->input('confirm_quota') !== true) {
            Response::json(['ok'=>false, 'error'=>'ارسال متن و بررسی سهمیه و هزینه احتمالی را تأیید کنید.'], 422);
        }
        $input = [];
        foreach (['provider', 'title', 'brief', 'facts'] as $key) {
            $value = $request->input($key, '');
            if (!is_string($value)) Response::json(['ok'=>false, 'error'=>'نوع داده ارسالی معتبر نیست.'], 422);
            $input[$key] = $value;
        }
        $provider = $input['provider'];
        $service = new AiArticleService((array)Config::get('ai', []));
        try {
            $prepared = $service->prepare($provider, $input['title'], $input['brief'], $input['facts']);
        } catch (RuntimeException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 422);
        }
        try {
            $reserved = self::reserveQuota((int)$user['id'], $provider);
        } catch (Throwable $e) {
            // Database errors can contain host names or SQL details.
            Response::json(['ok'=>false, 'error'=>'محدودیت درخواست آماده نیست؛ وضعیت دیتابیس را بررسی کنید.'], 500);
        }
        if (!$reserved) Response::json(['ok'=>false, 'error'=>'محدودیت تعداد درخواست پنل رسیده است؛ بعداً دوباره تلاش کنید.'], 429);
        try {
            // Exactly one provider and one request. There is no retry or silent fallback.
            $text = $service->send($prepared);
            Response::json(['ok'=>true, 'text'=>$text, 'provider'=>$prepared['label'], 'model'=>$prepared['model']]);
        } catch (RuntimeException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 502);
        } catch (Throwable $e) {
            // Do not log exceptions that may contain prompts, keys or provider responses.
            Response::json(['ok'=>false, 'error'=>'تولید متن انجام نشد؛ تنظیمات سرویس را بررسی کنید.'], 500);
        }
    }

    /** One transaction serializes all checks, including requests from different users. */
    private static function reserveQuota(int $userId, string $provider): bool
    {
        $perUser = max(1, min(100, (int)Config::get('ai.hourly_user_limit', 5)));
        $perSite = max(1, min(500, (int)Config::get('ai.daily_site_limit', 30)));
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $lock = DB::fetchOne('SELECT id FROM ai_article_quota_lock WHERE id=1 FOR UPDATE');
            if ($lock === null) throw new RuntimeException('محدودیت درخواست آماده نیست؛ به‌روزرسانی دیتابیس را بررسی کنید.');
            $hour = (int)(DB::fetchOne('SELECT COUNT(*) AS n FROM ai_article_requests WHERE user_id=:id AND requested_at >= (NOW() - INTERVAL 1 HOUR)', ['id'=>$userId])['n'] ?? 0);
            $day = (int)(DB::fetchOne('SELECT COUNT(*) AS n FROM ai_article_requests WHERE requested_at >= (NOW() - INTERVAL 24 HOUR)')['n'] ?? 0);
            if ($hour >= $perUser || $day >= $perSite) { $pdo->rollBack(); return false; }
            DB::query('INSERT INTO ai_article_requests (user_id,provider,requested_at) VALUES (:user,:provider,NOW())', ['user'=>$userId, 'provider'=>$provider]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
