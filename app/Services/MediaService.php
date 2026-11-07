<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use RuntimeException;

/**
 * سرویس مدیریت و پردازش امن تصاویر با کتابخانه GD در PHP 8.3
 * بررسی MIME واقعی با finfo، محدودیت حجم، بازنویسی کامل با GD (حذف EXIF/کد مخرب)
 * و تولید ۳ سایز استاندارد WebP (عرض‌های 480، 800 و 1200 پیکسل)
 */
final class MediaService
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // حداکثر ۵ مگابایت
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];
    private const TARGET_WIDTHS = [480, 800, 1200];

    /**
     * پردازش فایل آپلودشده از $_FILES، تبدیل به WebP در ۳ سایز و ثبت در جدول media
     *
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public static function handleUpload(array $uploadedFile, string $altText = ''): array
    {
        if (!isset($uploadedFile['error']) || is_array($uploadedFile['error'])) {
            throw new RuntimeException('ساختار فایل ارسالی نامعتبر است. لطفاً یک فایل تصویر انتخاب کنید.');
        }

        if ((int) $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('خطا در دریافت فایل آپلودشده. حجم فایل نباید بیشتر از ۵ مگابایت باشد.');
        }

        $tmpPath = (string) ($uploadedFile['tmp_name'] ?? '');
        $sizeBytes = (int) ($uploadedFile['size'] ?? 0);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('فایل موقت آپلود در سرور یافت نشد.');
        }

        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('حجم تصویر باید کمتر از ۵ مگابایت باشد. لطفاً تصویر سبک‌تری انتخاب کنید.');
        }

        // ۱. بررسی MIME واقعی فایل با finfo (نه پسوند فایل)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = (string) $finfo->file($tmpPath);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('فرمت تصویر مجاز نیست. فقط تصاویر واقعی با فرمت JPG، PNG یا WebP قابل قبول هستند.');
        }

        if (!extension_loaded('gd')) {
            throw new RuntimeException('افزونه GD روی سرور فعال نیست. لطفاً از طریق check.php وضعیت GD را بررسی کنید.');
        }

        // ۲. بارگذاری تصویر در حافظه GD برای حذف هرگونه داده اضافی و بازنویسی امن
        $srcImage = match ($realMime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png' => @imagecreatefrompng($tmpPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default => false,
        };

        if ($srcImage === false) {
            throw new RuntimeException('تصویر ارسالی آسیب‌دیده است و قابل پردازش توسط GD نیست.');
        }

        $origWidth = imagesx($srcImage);
        $origHeight = imagesy($srcImage);

        if ($origWidth < 100 || $origHeight < 100) {
            unset($srcImage);
            throw new RuntimeException('ابعاد تصویر بسیار کوچک است (حداقل ۱۰۰×۱۰۰ پیکسل الزامی است).');
        }

        $subDir = date('Y/m');
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $uploadDir = $base . '/public_html/uploads/' . $subDir;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            unset($srcImage);
            throw new RuntimeException('امکان ساخت پوشه آپلود در public_html/uploads وجود ندارد. دسترسی 755 پوشه را بررسی کنید.');
        }

        $token = bin2hex(random_bytes(8));
        $generatedPaths = [];
        $finalWidth800 = $origWidth;
        $finalHeight800 = $origHeight;
        $finalSizeBytes = 0;

        foreach (self::TARGET_WIDTHS as $targetW) {
            $newW = min($origWidth, $targetW);
            $ratio = $newW / $origWidth;
            $newH = max(1, (int) round($origHeight * $ratio));

            $canvas = imagecreatetruecolor($newW, $newH);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);

            imagecopyresampled(
                $canvas,
                $srcImage,
                0,
                0,
                0,
                0,
                $newW,
                $newH,
                $origWidth,
                $origHeight
            );

            $relPath = "/uploads/{$subDir}/img_{$token}_{$targetW}.webp";
            $absPath = $base . '/public_html' . $relPath;

            if (function_exists('imagewebp')) {
                imagewebp($canvas, $absPath, 82);
            } else {
                imagejpeg($canvas, $absPath, 85);
            }

            // GD images are objects on PHP 8; releasing the reference avoids
            // deprecated imagedestroy() output corrupting JSON on PHP 8.5.
            unset($canvas);
            $generatedPaths[$targetW] = $relPath;

            if ($targetW === 800) {
                $finalWidth800 = $newW;
                $finalHeight800 = $newH;
                $finalSizeBytes = is_file($absPath) ? (int) filesize($absPath) : $sizeBytes;
            }
        }

        unset($srcImage);

        DB::query(
            "INSERT INTO media (file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at)
             VALUES (:p480, :p800, :p1200, 'image/webp', :w, :h, :sz, :alt, NOW())",
            [
                'p480' => $generatedPaths[480],
                'p800' => $generatedPaths[800],
                'p1200' => $generatedPaths[1200],
                'w' => $finalWidth800,
                'h' => $finalHeight800,
                'sz' => $finalSizeBytes,
                'alt' => trim($altText),
            ]
        );

        $mediaId = DB::lastInsertId();

        return [
            'id' => $mediaId,
            'file_path_480' => $generatedPaths[480],
            'file_path_800' => $generatedPaths[800],
            'file_path_1200' => $generatedPaths[1200],
            'mime_type' => 'image/webp',
            'width' => $finalWidth800,
            'height' => $finalHeight800,
            'size_bytes' => $finalSizeBytes,
            'alt_text' => trim($altText),
        ];
    }
}
