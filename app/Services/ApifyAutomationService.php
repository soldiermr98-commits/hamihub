<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** One opt-in task, started by the existing host cron, with results staged for review. */
final class ApifyAutomationService
{
    private const MAX_BYTES = 4 * 1024 * 1024;
    private const OVERSIZE = 1001;
    private string $token;
    /** @var callable|null Test-only transport: fn(method, path, body, byteLimit): string */
    private $transport;

    public function __construct(string $token, ?callable $transport = null)
    {
        $this->token = trim($token);
        $this->transport = $transport;
    }

    public static function configured(): self
    {
        return new self((string)Config::get('providers.apify.api_token', ''));
    }

    private static function remoteId(string $id): string
    {
        $id = trim($id);
        if (!preg_match('/^[A-Za-z0-9_-]{5,100}$/D', $id)) throw new InvalidArgumentException('شناسه اجرای Apify معتبر نیست.');
        return $id;
    }

    private static function taskId(string $id): string
    {
        $id = trim($id);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.~-]{2,99}$/D', $id)) throw new InvalidArgumentException('شناسه Task در Apify معتبر نیست.');
        return $id;
    }

    /** Fixed HTTPS origin, bearer header, no redirect and bounded response. Never log tokens or response bodies. */
    private function request(string $method, string $path, ?string $body = null, int $limit = 262144): string
    {
        if ($this->token === '') throw new RuntimeException('توکن Apify در تنظیمات خصوصی هاست تنظیم نشده است.');
        if ($this->transport !== null) return ($this->transport)($method, $path, $body, $limit);
        if (!function_exists('curl_init')) throw new RuntimeException('افزونه curl روی هاست فعال نیست.');
        $ch = curl_init('https://api.apify.com' . $path);
        if ($ch === false) throw new RuntimeException('شروع اتصال Apify ناموفق بود.');
        $buffer = ''; $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>35,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $this->token, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$buffer, &$tooLarge, $limit): int {
                if (strlen($buffer) + strlen($chunk) > $limit) { $tooLarge = true; return 0; }
                $buffer .= $chunk; return strlen($chunk);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('خروجی Apify بیش از ۴ مگابایت است؛ داده را در Apify کوچک‌تر کنید.', self::OVERSIZE);
        if ($ok === false) throw new RuntimeException('ارتباط امن با Apify برقرار نشد؛ دسترسی اینترنت و گواهی هاست را بررسی کنید.');
        if ($http < 200 || $http >= 300) throw new RuntimeException('درخواست Apify ناموفق بود (HTTP ' . $http . ').');
        return $buffer;
    }

    private function json(string $method, string $path, ?string $body = null, int $limit = 262144): array
    {
        $raw = $this->request($method, $path, $body, $limit);
        if (strlen($raw) > $limit) throw new RuntimeException('خروجی Apify بیش از حد مجاز است.', self::OVERSIZE);
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('پاسخ JSON سرویس Apify معتبر نیست.');
        return $parsed;
    }

    private function getRun(string $id): array
    {
        $response = $this->json('GET', '/v2/actor-runs/' . rawurlencode(self::remoteId($id)));
        if (!is_array($response['data'] ?? null) || ($response['data']['id'] ?? null) !== $id) throw new RuntimeException('شناسه پاسخ اجرای Apify با درخواست یکسان نیست.');
        return $response['data'];
    }

    private function getDataset(string $datasetId): array
    {
        $id = self::remoteId($datasetId);
        $items = $this->json('GET', '/v2/datasets/' . rawurlencode($id) . '/items?format=json&limit=501', null, self::MAX_BYTES);
        if (!array_is_list($items)) throw new RuntimeException('خروجی Dataset آرایه JSON نیست.');
        if (count($items) > ManualImportService::MAX_RECORDS) throw new RuntimeException('خروجی بیش از ۵۰۰ رکورد دارد؛ آن را در Apify تقسیم یا محدود کنید.', self::OVERSIZE);
        return $items;
    }

    private static function lockName(): string { return 'hhx_apify_' . substr(hash('sha256', (string)Config::get('db.database', '')), 0, 40); }
    private static function acquire(): bool
    {
        return (int)(DB::fetchOne('SELECT GET_LOCK(:name, 0) AS acquired', ['name'=>self::lockName()])['acquired'] ?? 0) === 1;
    }
    private static function release(): void { DB::fetchOne('SELECT RELEASE_LOCK(:name) AS released', ['name'=>self::lockName()]); }

    public function status(): array
    {
        $schedule = DB::fetchOne('SELECT enabled,task_id,city_id,category_id,max_charge_usd,source_categories_json,min_rating,min_reviews,next_run_at,last_error FROM apify_automation_settings WHERE id=1');
        $runs = DB::fetchAll('SELECT id,task_id,remote_run_id,dataset_id,status,city_id,category_id,started_at_iso,finished_at_iso,item_count,error,created_at,updated_at FROM apify_import_runs ORDER BY id DESC LIMIT 50');
        if ($schedule) {
            $categories = json_decode((string)($schedule['source_categories_json'] ?? ''), true);
            $schedule['source_categories'] = is_array($categories) ? $categories : [];
            unset($schedule['source_categories_json']);
        }
        return ['token_configured'=>$this->token !== '', 'schedule'=>$schedule, 'runs'=>$runs];
    }

    public function saveSchedule(array $input): void
    {
        if (!self::acquire()) throw new RuntimeException('پردازش Apify در حال انجام است؛ برای تغییر تنظیمات کمی بعد دوباره تلاش کنید.');
        try { $this->saveScheduleLocked($input); } finally { self::release(); }
    }

    private function saveScheduleLocked(array $input): void
    {
        $enable = ($input['enabled'] ?? false) === true;
        if (!$enable) {
            DB::query('UPDATE apify_automation_settings SET enabled=0 WHERE id=1');
            return;
        }
        if ($this->token === '') throw new InvalidArgumentException('ابتدا توکن جدید را فقط در config.local.php روی هاست تنظیم کنید.');
        $task = self::taskId((string)($input['task_id'] ?? ''));
        $cityId = (int)($input['city_id'] ?? 0); $categoryId = (int)($input['category_id'] ?? 0);
        if (!DB::fetchOne('SELECT id FROM cities WHERE id=:id AND is_active=1', ['id'=>$cityId]) ||
            !DB::fetchOne('SELECT id FROM categories WHERE id=:id AND is_active=1', ['id'=>$categoryId])) throw new InvalidArgumentException('شهر و دسته فعال را انتخاب کنید.');
        $cap = $input['max_charge_usd'] ?? null;
        if (!is_numeric($cap) || (float)$cap < 0.01 || (float)$cap > 100 || round((float)$cap, 2) !== (float)$cap) throw new InvalidArgumentException('سقف هزینه هر اجرا باید بین ۰٫۰۱ و ۱۰۰ دلار و با حداکثر دو رقم اعشار باشد.');
        $sourceCategories = $input['source_categories'] ?? [];
        if (!is_array($sourceCategories) || !array_is_list($sourceCategories) || count($sourceCategories) > 100) throw new InvalidArgumentException('دسته‌های منبع معتبر نیستند.');
        foreach ($sourceCategories as $sourceCategory) if (!is_string($sourceCategory) || trim($sourceCategory) === '' || mb_strlen($sourceCategory,'UTF-8') > 200) throw new InvalidArgumentException('نام دسته منبع معتبر نیست.');
        $sourceCategories = array_values(array_unique($sourceCategories));
        $minRating = $input['min_rating'] ?? 0; $minReviews = $input['min_reviews'] ?? 0;
        if (!is_numeric($minRating) || (float)$minRating < 0 || (float)$minRating > 5 || round((float)$minRating,1)!==(float)$minRating || !is_numeric($minReviews) || (float)$minReviews < 0 || (float)$minReviews > 4294967295 || floor((float)$minReviews)!==(float)$minReviews) throw new InvalidArgumentException('فیلتر امتیاز یا تعداد نظر معتبر نیست.');
        if (DB::fetchOne("SELECT id FROM apify_import_runs WHERE status IN ('starting','start_unknown') LIMIT 1")) throw new InvalidArgumentException('نتیجه آغاز یک اجرای قبلی نامشخص است؛ ابتدا آن را در Apify بررسی و در پنل رفع توقف کنید.');
        DB::query('UPDATE apify_automation_settings SET enabled=1,task_id=:task,city_id=:city,category_id=:cat,max_charge_usd=:cap,source_categories_json=:cats,min_rating=:rating,min_reviews=:reviews,next_run_at=COALESCE(next_run_at,NOW()),last_error=NULL WHERE id=1',
            ['task'=>$task,'city'=>$cityId,'cat'=>$categoryId,'cap'=>number_format((float)$cap,2,'.',''),'cats'=>json_encode($sourceCategories,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),'rating'=>(float)$minRating,'reviews'=>(int)$minReviews]);
    }

    /** Import a previously executed run, without starting any Actor. */
    public function importExisting(string $runId): int
    {
        $id = self::remoteId($runId);
        if (!self::acquire()) throw new RuntimeException('یک دریافت Apify هم‌اکنون در حال انجام است؛ کمی بعد تلاش کنید.');
        try {
            $existing = DB::fetchOne('SELECT id FROM apify_import_runs WHERE BINARY remote_run_id=BINARY :id', ['id'=>$id]);
            if ($existing) return (int)$existing['id'];
            $remote = $this->getRun($id);
            $task = is_string($remote['actorTaskId'] ?? null) ? substr($remote['actorTaskId'], 0, 100) : '';
            DB::query("INSERT INTO apify_import_runs (task_id,remote_run_id,status,started_at_iso) VALUES (:task,:run,'running',:started)",
                ['task'=>$task,'run'=>$id,'started'=>self::timeString($remote['startedAt'] ?? null)]);
            $localId = DB::lastInsertId();
            $this->processRemote($localId, $remote);
            return $localId;
        } finally { self::release(); }
    }

    /** Poll an already-started run on demand; this never starts or restarts an Actor. */
    public function refreshRun(int $id): void
    {
        if (!self::acquire()) throw new RuntimeException('دریافت Apify در حال انجام است؛ کمی بعد تلاش کنید.');
        try {
            $row = DB::fetchOne("SELECT id,remote_run_id FROM apify_import_runs WHERE id=:id AND status='running'", ['id'=>$id]);
            if (!$row || !is_string($row['remote_run_id'])) throw new InvalidArgumentException('اجرای در حال دریافت یافت نشد.');
            try { $this->processRemote($id, $this->getRun($row['remote_run_id'])); }
            catch (Throwable $e) {
                $message = $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'خطای داخلی در دریافت خروجی.';
                DB::query('UPDATE apify_import_runs SET error=:error WHERE id=:id', ['id'=>$id,'error'=>mb_substr($message,0,500)]);
                throw $e;
            }
        } finally { self::release(); }
    }

    private static function timeString(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) ? $value : null;
    }

    private function processRemote(int $localId, array $remote): void
    {
        $state = (string)($remote['status'] ?? '');
        if (in_array($state, ['READY','RUNNING','ABORTING','TIMING-OUT'], true)) {
            DB::query("UPDATE apify_import_runs SET status='running',error=NULL WHERE id=:id", ['id'=>$localId]); return;
        }
        if ($state !== 'SUCCEEDED') {
            DB::query("UPDATE apify_import_runs SET status='failed',error=:err,finished_at_iso=:finished WHERE id=:id", ['id'=>$localId,'err'=>'اجرای Apify موفق نبود: ' . substr(preg_replace('/[^A-Z-]/', '', $state),0,40),'finished'=>self::timeString($remote['finishedAt'] ?? null)]); return;
        }
        $datasetId = self::remoteId((string)($remote['defaultDatasetId'] ?? ''));
        try {
            $items = $this->getDataset($datasetId);
            $encoded = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encoded) > self::MAX_BYTES) throw new RuntimeException('خروجی Apify بیش از ۴ مگابایت است.', self::OVERSIZE);
            DB::query("UPDATE apify_import_runs SET status='ready',dataset_id=:dataset,dataset_json=:json,item_count=:count,started_at_iso=:started,finished_at_iso=:finished,error=NULL WHERE id=:id",
                ['id'=>$localId,'dataset'=>$datasetId,'json'=>$encoded,'count'=>count($items),'started'=>self::timeString($remote['startedAt'] ?? null),'finished'=>self::timeString($remote['finishedAt'] ?? null)]);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::OVERSIZE) throw $e;
            DB::query("UPDATE apify_import_runs SET status='too_large',dataset_id=:dataset,error=:err WHERE id=:id", ['id'=>$localId,'dataset'=>$datasetId,'err'=>$e->getMessage()]);
        }
    }

    public function runData(int $id): array
    {
        $row = DB::fetchOne('SELECT id,task_id,remote_run_id,status,city_id,category_id,source_categories_json,min_rating,min_reviews,started_at_iso,finished_at_iso,item_count,dataset_json FROM apify_import_runs WHERE id=:id', ['id'=>$id]);
        if (!$row || $row['status'] !== 'ready') throw new InvalidArgumentException('خروجی آماده برای این اجرا وجود ندارد.');
        $items = json_decode((string)$row['dataset_json'], true);
        if (!is_array($items) || !array_is_list($items)) throw new RuntimeException('خروجی ذخیره‌شده معتبر نیست.');
        $categories = json_decode((string)($row['source_categories_json'] ?? ''), true);
        $row['source_categories'] = is_array($categories) ? $categories : [];
        unset($row['dataset_json'], $row['source_categories_json']);
        $row['items'] = $items;
        return $row;
    }

    public function deleteReady(int $id): void
    {
        // Deletes only staged data, never businesses or source history.
        $stmt = DB::query("DELETE FROM apify_import_runs WHERE id=:id AND status IN ('ready','failed','too_large')", ['id'=>$id]);
        if (!$stmt->rowCount()) throw new InvalidArgumentException('فقط خروجی‌های آماده یا خاتمه‌یافته قابل حذف هستند.');
    }

    public function resolveUnknown(int $id): void
    {
        if (!self::acquire()) throw new RuntimeException('پردازش Apify در حال انجام است؛ کمی بعد دوباره تلاش کنید.');
        try {
            $stmt = DB::query("UPDATE apify_import_runs SET status='failed',error='مدیر پس از بررسی حساب Apify توقف را بست.' WHERE id=:id AND status='start_unknown'", ['id'=>$id]);
            if (!$stmt->rowCount()) throw new InvalidArgumentException('اجرای نامشخص یافت نشد.');
            // Schedule stays disabled until the administrator explicitly re-enables it.
        } finally { self::release(); }
    }

    /** Called from cron; never retries an uncertain POST that might already have incurred a charge. */
    public function tick(): array
    {
        if ($this->token === '') return ['status'=>'disabled'];
        if (!self::acquire()) return ['status'=>'busy'];
        try {
            $starting = DB::fetchOne("SELECT id,(updated_at <= NOW() - INTERVAL 3 MINUTE) AS timed_out FROM apify_import_runs WHERE status='starting' ORDER BY id LIMIT 1");
            if ($starting) {
                if ((int)$starting['timed_out'] === 1) {
                    DB::query("UPDATE apify_import_runs SET status='start_unknown',error='نتیجه آغاز اجرا نامشخص است؛ پیش از فعال‌سازی دوباره حساب Apify را بررسی کنید.' WHERE id=:id", ['id'=>(int)$starting['id']]);
                    DB::query("UPDATE apify_automation_settings SET enabled=0,last_error='نتیجه آغاز اجرای قبلی نامشخص است.' WHERE id=1");
                    return ['status'=>'start_unknown'];
                }
                return ['status'=>'starting'];
            }
            if (DB::fetchOne("SELECT id FROM apify_import_runs WHERE status='start_unknown' LIMIT 1")) return ['status'=>'start_unknown'];
            $pending = DB::fetchOne("SELECT id,remote_run_id FROM apify_import_runs WHERE status='running' ORDER BY id LIMIT 1");
            if ($pending) {
                try {
                    $remote = $this->getRun((string)$pending['remote_run_id']);
                    $this->processRemote((int)$pending['id'], $remote);
                    return ['status'=>'checked', 'run_id'=>(int)$pending['id']];
                } catch (Throwable $e) {
                    $msg = $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'خطای داخلی در دریافت خروجی.';
                    DB::query('UPDATE apify_import_runs SET error=:error WHERE id=:id', ['id'=>(int)$pending['id'],'error'=>mb_substr($msg,0,500)]);
                    return ['status'=>'fetch_error'];
                }
            }
            $settings = DB::fetchOne('SELECT *, (next_run_at IS NOT NULL AND next_run_at<=NOW()) AS due FROM apify_automation_settings WHERE id=1');
            if (!$settings || (int)$settings['enabled'] !== 1 || (int)$settings['due'] !== 1) return ['status'=>'waiting'];
            try {
                $task = self::taskId((string)$settings['task_id']);
                $amount = $settings['max_charge_usd'];
                if (!is_numeric($amount) || (float)$amount < 0.01 || (float)$amount > 100) throw new RuntimeException('سقف هزینه اجرای Apify معتبر نیست.');
                if (!DB::fetchOne('SELECT id FROM cities WHERE id=:id AND is_active=1', ['id'=>(int)$settings['city_id']]) || !DB::fetchOne('SELECT id FROM categories WHERE id=:id AND is_active=1', ['id'=>(int)$settings['category_id']])) throw new RuntimeException('شهر یا دسته انتخاب‌شده فعال نیست.');
                $taskData = $this->json('GET', '/v2/actor-tasks/' . rawurlencode($task));
                if (!is_array($taskData['data'] ?? null) || !is_string($taskData['data']['id'] ?? null)) throw new RuntimeException('Task در Apify یافت نشد.');
                // Read the documented input endpoint explicitly; absent task metadata must not bypass checks.
                $input = $this->json('GET', '/v2/actor-tasks/' . rawurlencode($task) . '/input');
                if (!is_array($input) || !$input || array_is_list($input)) throw new RuntimeException('ورودی Task باید یک شیء JSON غیرخالی و سازگار باشد.');
                // Known Vortex input is per query/location, not a global maximum.
                if (isset($input['maxCrawledPlacesPerSearch'])) {
                    $max = $input['maxCrawledPlacesPerSearch'];
                    if (!is_numeric($max) || (float)$max < 1 || (float)$max > 500 || floor((float)$max) !== (float)$max) throw new RuntimeException('سقف مکان هر جستجو در Task باید عدد صحیح بین ۱ تا ۵۰۰ باشد.');
                }
                foreach (['searchStringsArray','locationQueries','languages'] as $field) {
                    if (isset($input[$field]) && (!is_array($input[$field]) || count($input[$field]) > 1)) throw new RuntimeException('برای کنترل حجم، Task را با یک عبارت، یک شهر و یک زبان تنظیم کنید.');
                }
                $payload = json_encode((object)$input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $msg = $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'بررسی Task ناموفق بود.';
                DB::query('UPDATE apify_automation_settings SET enabled=0,last_error=:error WHERE id=1', ['error'=>mb_substr($msg,0,500)]);
                return ['status'=>'preflight_error'];
            }
            $pdo = DB::connection(); $pdo->beginTransaction();
            try {
                DB::query("INSERT INTO apify_import_runs (task_id,status,city_id,category_id,source_categories_json,min_rating,min_reviews) VALUES (:task,'starting',:city,:category,:cats,:rating,:reviews)", ['task'=>$task,'city'=>$settings['city_id'],'category'=>$settings['category_id'],'cats'=>$settings['source_categories_json'],'rating'=>$settings['min_rating'],'reviews'=>$settings['min_reviews']]);
                $localId = DB::lastInsertId();
                DB::query('UPDATE apify_automation_settings SET next_run_at=DATE_ADD(NOW(), INTERVAL 14 DAY),last_error=NULL WHERE id=1');
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            try {
                $cap = number_format((float)$settings['max_charge_usd'], 2, '.', '');
                $res = $this->json('POST', '/v2/actor-tasks/' . rawurlencode($task) . '/runs?restartOnError=false&maxItems=500&maxTotalChargeUsd=' . $cap, $payload);
                $data = $res['data'] ?? null;
                if (!is_array($data) || !is_string($data['id'] ?? null)) throw new RuntimeException('پاسخ آغاز اجرا شناسه معتبر ندارد.');
                $remoteId = self::remoteId($data['id']);
                DB::query("UPDATE apify_import_runs SET status='running',remote_run_id=:run,started_at_iso=:started WHERE id=:id", ['id'=>$localId,'run'=>$remoteId,'started'=>self::timeString($data['startedAt'] ?? null)]);
                return ['status'=>'started','run_id'=>$localId];
            } catch (Throwable $e) {
                // Even a timeout or a malformed response can mean the Actor actually started.
                DB::query("UPDATE apify_import_runs SET status='start_unknown',error='نتیجه آغاز اجرا نامشخص است؛ حساب Apify را بررسی کنید.' WHERE id=:id", ['id'=>$localId]);
                DB::query("UPDATE apify_automation_settings SET enabled=0,last_error='نتیجه آغاز اجرا نامشخص است؛ پیش از تکرار حساب Apify را بررسی کنید.' WHERE id=1");
                return ['status'=>'start_unknown','run_id'=>$localId];
            }
        } finally { self::release(); }
    }
}
