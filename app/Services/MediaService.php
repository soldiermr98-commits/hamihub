<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use PDO;
use RuntimeException;
use Throwable;

final class MediaUploadException extends RuntimeException {}

/**
 * Validate and re-encode uploads as real WebP images; never trust the filename
 * or report success when a derivative or its database record could not be saved.
 */
final class MediaService
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const MAX_DIMENSION = 10000;
    private const MAX_PIXELS = 16000000;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const TARGET_WIDTHS = [480, 800, 1200];

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public static function handleUpload(array $uploadedFile, string $altText = ''): array
    {
        if (!isset($uploadedFile['error']) || is_array($uploadedFile['error'])) {
            throw new MediaUploadException('ساختار فایل ارسالی نامعتبر است. لطفاً یک فایل تصویر انتخاب کنید.');
        }
        if ((int)$uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new MediaUploadException('خطا در دریافت فایل آپلودشده. حجم فایل نباید بیشتر از ۵ مگابایت باشد.');
        }
        $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');
        $reportedSize = (int)($uploadedFile['size'] ?? 0);
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            throw new MediaUploadException('فایل موقت آپلود در سرور یافت نشد یا معتبر نیست.');
        }
        $actualSize = @filesize($tmpPath);
        if ($reportedSize <= 0 || $reportedSize > self::MAX_UPLOAD_BYTES || !is_int($actualSize) || $actualSize <= 0 || $actualSize > self::MAX_UPLOAD_BYTES) {
            throw new MediaUploadException('حجم تصویر باید حداکثر ۵ مگابایت باشد. لطفاً تصویر سبک‌تری انتخاب کنید.');
        }
        if (!function_exists('mb_check_encoding') || !function_exists('mb_strlen')) {
            throw new MediaUploadException('افزونه mbstring روی سرور فعال نیست.');
        }
        $altText = trim($altText);
        if (!mb_check_encoding($altText, 'UTF-8') || mb_strlen($altText, 'UTF-8') > 255 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $altText)) {
            throw new MediaUploadException('متن جایگزین تصویر باید معتبر و حداکثر ۲۵۵ نویسه باشد.');
        }
        if (!class_exists(\finfo::class)) {
            throw new MediaUploadException('افزونه Fileinfo روی سرور فعال نیست.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = (string)$finfo->file($tmpPath);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new MediaUploadException('فرمت تصویر مجاز نیست. فقط تصاویر واقعی با فرمت JPG، PNG یا WebP قابل قبول هستند.');
        }
        if (!extension_loaded('gd')) {
            throw new MediaUploadException('افزونه GD روی سرور فعال نیست. لطفاً وضعیت آن را بررسی کنید.');
        }
        // Do not fall back to JPEG under a .webp filename: callers and database
        // explicitly rely on the three derivatives being WebP.
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromwebp') || !function_exists('imagetypes') || !defined('IMG_WEBP') || (imagetypes() & IMG_WEBP) === 0) {
            throw new MediaUploadException('پشتیبانی WebP در افزونه GD فعال نیست؛ از مدیر هاست بخواهید آن را فعال کند.');
        }
        $info = @getimagesize($tmpPath);
        if (!is_array($info) || ($info['mime'] ?? '') !== $realMime || !isset($info[0], $info[1])) {
            throw new MediaUploadException('تصویر ارسالی آسیب‌دیده است و قابل پردازش نیست.');
        }
        $origWidth = (int)$info[0]; $origHeight = (int)$info[1];
        if ($origWidth < 100 || $origHeight < 100) {
            throw new MediaUploadException('ابعاد تصویر بسیار کوچک است (حداقل ۱۰۰×۱۰۰ پیکسل الزامی است).');
        }
        if ($origWidth > self::MAX_DIMENSION || $origHeight > self::MAX_DIMENSION || $origWidth * $origHeight > self::MAX_PIXELS) {
            throw new MediaUploadException('ابعاد تصویر بسیار بزرگ است؛ هر ضلع حداکثر ۱۰٬۰۰۰ و مساحت حداکثر ۱۶ میلیون پیکسل است.');
        }
        // A small compressed file can expand to a large bitmap. This estimate is
        // intentionally conservative, but cannot guarantee availability on every host.
        $largestWidth = min($origWidth, max(self::TARGET_WIDTHS));
        $largestHeight = max(1, (int)round($origHeight * ($largestWidth / $origWidth)));
        $estimatedBytes = ($origWidth * $origHeight + $largestWidth * $largestHeight) * 6 + 16 * 1024 * 1024;
        $limit = self::memoryLimitBytes();
        if ($limit !== null && $estimatedBytes > max(0, $limit - memory_get_usage(true))) {
            throw new MediaUploadException('حافظه PHP برای پردازش این ابعاد کافی نیست؛ تصویر کوچک‌تری انتخاب کنید.');
        }

        $srcImage = match ($realMime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpPath) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default => false,
        };
        if ($srcImage === false || imagesx($srcImage) !== $origWidth || imagesy($srcImage) !== $origHeight) {
            throw new MediaUploadException('تصویر ارسالی آسیب‌دیده است یا فرمت آن در GD پشتیبانی نمی‌شود.');
        }
        $subDir = date('Y/m');
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $uploadDir = $base . '/public_html/uploads/' . $subDir;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new MediaUploadException('امکان ساخت پوشه آپلود وجود ندارد. دسترسی پوشه را بررسی کنید.');
        }

        $token = bin2hex(random_bytes(8));
        $generatedPaths = []; $ownedFiles = [];
        $finalWidth800 = 0; $finalHeight800 = 0; $finalSizeBytes = 0;
        $pdo = null; $transactionOwned = false; $commitAttempted = false;
        try {
            foreach (self::TARGET_WIDTHS as $targetW) {
                $newW = min($origWidth, $targetW);
                $newH = max(1, (int)round($origHeight * ($newW / $origWidth)));
                $canvas = @imagecreatetruecolor($newW, $newH);
                if ($canvas === false) throw new MediaUploadException('پردازش اندازه تصویر ناموفق بود.');
                if (!@imagealphablending($canvas, false) || !@imagesavealpha($canvas, true) ||
                    !@imagecopyresampled($canvas, $srcImage, 0, 0, 0, 0, $newW, $newH, $origWidth, $origHeight)) {
                    throw new MediaUploadException('پردازش اندازه تصویر ناموفق بود.');
                }
                $relPath = "/uploads/{$subDir}/img_{$token}_{$targetW}.webp";
                $absPath = $base . '/public_html' . $relPath;
                // Exclusive creation avoids overwriting or cleaning up another image
                // even in the unlikely event of a filename collision.
                $file = @fopen($absPath, 'xb');
                if ($file === false) throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
                $ownedFiles[] = $absPath;
                $written = false; $flushed = false; $closed = false;
                try {
                    $written = @imagewebp($canvas, $file, 82);
                    $flushed = @fflush($file);
                } finally {
                    $closed = @fclose($file);
                    // GD images are objects on PHP 8; avoid deprecated imagedestroy().
                    unset($canvas);
                }
                if (!$written || !$flushed || !$closed) {
                    throw new MediaUploadException('ذخیره تصویر WebP کامل نشد. فضای دیسک و دسترسی پوشه را بررسی کنید.');
                }
                clearstatcache(true, $absPath);
                $outputSize = @filesize($absPath);
                $outputInfo = @getimagesize($absPath);
                if (!is_int($outputSize) || $outputSize <= 0 || !is_array($outputInfo) ||
                    ($outputInfo['mime'] ?? '') !== 'image/webp' || (int)$outputInfo[0] !== $newW || (int)$outputInfo[1] !== $newH) {
                    throw new MediaUploadException('فایل WebP تولیدشده معتبر نیست؛ ذخیره تصویر متوقف شد.');
                }
                // Header inspection alone can accept a truncated file. Decode the
                // completed derivative before a database row can refer to it.
                $verified = @imagecreatefromwebp($absPath);
                if ($verified === false || imagesx($verified) !== $newW || imagesy($verified) !== $newH) {
                    throw new MediaUploadException('فایل WebP تولیدشده معتبر نیست؛ ذخیره تصویر متوقف شد.');
                }
                unset($verified);
                $generatedPaths[$targetW] = $relPath;
                if ($targetW === 800) {
                    $finalWidth800 = $newW; $finalHeight800 = $newH; $finalSizeBytes = $outputSize;
                }
            }

            // The media table is InnoDB. Roll back a failed insert before removing
            // newly created files. A lost connection during COMMIT is ambiguous:
            // keep the files for review rather than risk breaking a committed row.
            $pdo = DB::connection();
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                throw new MediaUploadException('ثبت تصویر در پایگاه داده آماده نیست.');
            }
            $transactionOwned = true;
            DB::query(
                "INSERT INTO media (file_path_480, file_path_800, file_path_1200, mime_type, width, height, size_bytes, alt_text, created_at)
                 VALUES (:p480, :p800, :p1200, 'image/webp', :w, :h, :sz, :alt, NOW())",
                ['p480'=>$generatedPaths[480], 'p800'=>$generatedPaths[800], 'p1200'=>$generatedPaths[1200],
                 'w'=>$finalWidth800, 'h'=>$finalHeight800, 'sz'=>$finalSizeBytes, 'alt'=>$altText]
            );
            $mediaId = (int)DB::lastInsertId();
            if ($mediaId <= 0) throw new MediaUploadException('ثبت تصویر در پایگاه داده کامل نشد.');
            $commitAttempted = true;
            if (!$pdo->commit()) throw new MediaUploadException('نتیجه ثبت تصویر نامشخص است؛ پیش از تلاش دوباره وضعیت را بررسی کنید.');
            $transactionOwned = false;
        } catch (Throwable $error) {
            $safeToRemove = !$commitAttempted;
            if ($transactionOwned && $pdo instanceof PDO) {
                try {
                    // A successful rollback after a failed COMMIT cannot prove
                    // that the server did not already commit the earlier request.
                    if ($pdo->inTransaction()) {
                        $rolledBack = $pdo->rollBack();
                        if (!$commitAttempted) $safeToRemove = $rolledBack;
                    }
                } catch (Throwable $ignored) { $safeToRemove = false; }
            }
            $cleanupFailed = false;
            if ($safeToRemove) {
                foreach ($ownedFiles as $filePath) {
                    if (!@unlink($filePath) && is_file($filePath)) $cleanupFailed = true;
                }
            }
            if ($commitAttempted) {
                throw new MediaUploadException('نتیجه ثبت تصویر نامشخص است؛ پیش از تلاش دوباره وضعیت رسانه را بررسی کنید.', 0, $error);
            }
            if (!$safeToRemove) {
                throw new MediaUploadException('لغو ثبت تصویر نامشخص است؛ فایل‌ها حفظ شدند و باید پیش از تلاش دوباره بررسی شوند.', 0, $error);
            }
            if ($cleanupFailed) {
                throw new MediaUploadException('ذخیره تصویر انجام نشد و پاک‌سازی فایل ناقص بود؛ از مدیر هاست بخواهید وضعیت را بررسی کند.', 0, $error);
            }
            if ($error instanceof MediaUploadException) throw $error;
            throw new MediaUploadException('امکان ذخیره تصویر وجود ندارد؛ وضعیت سرور و پایگاه داده را بررسی کنید.', 0, $error);
        } finally {
            unset($srcImage);
        }

        return [
            'id'=>$mediaId, 'file_path_480'=>$generatedPaths[480], 'file_path_800'=>$generatedPaths[800],
            'file_path_1200'=>$generatedPaths[1200], 'mime_type'=>'image/webp', 'width'=>$finalWidth800,
            'height'=>$finalHeight800, 'size_bytes'=>$finalSizeBytes, 'alt_text'=>$altText,
        ];
    }

    /** Parse conventional PHP memory_limit values; -1 means unlimited. */
    private static function memoryLimitBytes(): ?int
    {
        $value = trim((string)ini_get('memory_limit'));
        if ($value === '' || $value === '-1' || !preg_match('/\A(\d+)\s*([kmg]?)\z/i', $value, $match)) return null;
        $amount = (int)$match[1];
        $factor = match (strtolower($match[2])) { 'k'=>1024, 'm'=>1048576, 'g'=>1073741824, default=>1 };
        if ($amount > intdiv(PHP_INT_MAX, $factor)) return null;
        return $amount * $factor;
    }
}
