<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Response;
use Throwable;

final class AdvertisingService
{
    // New placements can be registered here without changing the DB schema.
    public const PLACEMENTS = [
        'home'=>'صفحه اصلی', 'city'=>'صفحه شهر', 'category'=>'صفحه شهر و دسته',
        'business'=>'صفحه کسب‌وکار', 'article'=>'صفحه مقاله',
    ];

    public static function schemaReady(): bool
    {
        try {
            return DB::fetchOne("SHOW TABLES LIKE 'advertising_banners'") !== null
                && DB::fetchOne("SHOW TABLES LIKE 'advertising_inquiries'") !== null;
        } catch (Throwable $e) { return false; }
    }

    public static function validTarget(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
        if (str_starts_with($url, '/')) return !str_starts_with($url, '//');
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function validImagePath(string $path): bool
    {
        return (bool)preg_match('~\A/uploads/[a-zA-Z0-9_\-/]+\.webp\z~', $path);
    }

    /**
     * Returns a fully escaped SSR banner and the next relevant schedule boundary.
     * Cache reads expire at that boundary, even without a cron job.
     * @return array{html:string,until:int}
     */
    public static function placement(string $slot, ?int $cityId, ?int $categoryId, ?string $now = null): array
    {
        $empty = ['html'=>'', 'until'=>0];
        if (!isset(self::PLACEMENTS[$slot]) || !self::schemaReady()) return $empty;
        $now ??= date('Y-m-d H:i:s');
        $target = 'a.placement=:slot AND a.active=1 AND (a.city_id IS NULL OR a.city_id=:city) AND (a.category_id IS NULL OR a.category_id=:category)';
        $params = ['slot'=>$slot, 'city'=>$cityId ?? 0, 'category'=>$categoryId ?? 0];
        $future = DB::fetchOne(
            "SELECT MIN(CASE WHEN a.starts_at > :now_start THEN a.starts_at ELSE NULL END) AS next_start,
                    MIN(CASE WHEN a.ends_at > :now_end THEN a.ends_at ELSE NULL END) AS next_end
             FROM advertising_banners a WHERE {$target}",
            ['now_start'=>$now, 'now_end'=>$now] + $params
        );
        $until = 0;
        foreach (['next_start','next_end'] as $key) {
            if (!empty($future[$key])) {
                $timestamp = strtotime((string)$future[$key]);
                if ($timestamp !== false && ($until === 0 || $timestamp < $until)) $until = $timestamp;
            }
        }
        $banner = DB::fetchOne(
            "SELECT a.id,a.title,a.alt_text,a.target_url,m.file_path_1200,m.width,m.height
             FROM advertising_banners a INNER JOIN media m ON m.id=a.media_id
             WHERE {$target} AND (a.starts_at IS NULL OR a.starts_at <= :starts_now)
               AND (a.ends_at IS NULL OR a.ends_at > :ends_now)
             ORDER BY a.priority DESC,a.id DESC LIMIT 1",
            $params + ['starts_now'=>$now, 'ends_now'=>$now]
        );
        if (!$banner || !self::validTarget((string)$banner['target_url']) || !self::validImagePath((string)$banner['file_path_1200'])) {
            return ['html'=>'', 'until'=>$until];
        }
        $html = '<aside class="hx-ad-wrap hx-container" aria-label="تبلیغات"><span class="hx-ad-label">تبلیغ</span>'
            . '<a class="hx-ad-banner" href="' . Response::e((string)$banner['target_url']) . '" target="_blank" rel="sponsored noopener noreferrer">'
            . '<img src="' . Response::e((string)$banner['file_path_1200']) . '" alt="' . Response::e((string)$banner['alt_text'])
            . '" loading="lazy" decoding="async" width="' . max(1,(int)$banner['width']) . '" height="' . max(1,(int)$banner['height']) . '">'
            . '</a></aside>';
        return ['html'=>$html, 'until'=>$until];
    }
}
