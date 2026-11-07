<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A deliberately small, server-side provider boundary. Adding a provider means
 * adding an adapter and registering it here; request bodies can never choose a URL.
 * No prompt, credential, or provider response is written to the database/logs.
 */
interface ArticleTextProvider
{
    /** @return array{url:string,headers:string[],body:array} */
    public function request(string $model, string $apiKey, string $prompt): array;
    public function text(array $response): string;
}

final class GeminiArticleProvider implements ArticleTextProvider
{
    public function request(string $model, string $apiKey, string $prompt): array
    {
        return [
            'url'=>'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            'headers'=>['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            // Newer Gemini models ignore (and future ones may reject) sampling parameters.
            'body'=>['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],
                'generationConfig'=>['maxOutputTokens'=>2048]],
        ];
    }
    public function text(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        if (is_array($parts)) foreach ($parts as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
        }
        return $text;
    }
}

final class ChatArticleProvider implements ArticleTextProvider
{
    public function __construct(private readonly string $url) {}
    public function request(string $model, string $apiKey, string $prompt): array
    {
        return [
            'url'=>$this->url,
            'headers'=>['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            'body'=>['model'=>$model, 'messages'=>[['role'=>'user','content'=>$prompt]],
                'temperature'=>0.4, 'max_tokens'=>2048],
        ];
    }
    public function text(array $response): string
    {
        $text = $response['choices'][0]['message']['content'] ?? '';
        return is_string($text) ? $text : '';
    }
}

final class AiArticleService
{
    /** @var array<string, array{label:string,adapter:ArticleTextProvider}> */
    private array $providers;

    /** The fixed registry is also used by the admin UI; browser input never selects a URL. */
    public static function catalog(): array
    {
        return [
            'gemini'=>['label'=>'Gemini', 'default_model'=>'gemini-3.5-flash-lite'],
            'groq'=>['label'=>'Groq', 'default_model'=>'llama-3.1-8b-instant'],
            'openrouter'=>['label'=>'OpenRouter', 'default_model'=>'openrouter/free'],
        ];
    }

    public function __construct(private readonly array $config)
    {
        $catalog = self::catalog();
        $this->providers = [
            'gemini'=>['label'=>$catalog['gemini']['label'], 'adapter'=>new GeminiArticleProvider()],
            'groq'=>['label'=>$catalog['groq']['label'], 'adapter'=>new ChatArticleProvider('https://api.groq.com/openai/v1/chat/completions')],
            'openrouter'=>['label'=>$catalog['openrouter']['label'], 'adapter'=>new ChatArticleProvider('https://openrouter.ai/api/v1/chat/completions')],
        ];
    }

    public function status(): array
    {
        $out = [];
        foreach ($this->providers as $id=>$provider) {
            $c = $this->config['providers'][$id] ?? [];
            if (!is_array($c)) $c = [];
            $model = is_string($c['model'] ?? null) ? trim($c['model']) : '';
            $validModel = $this->validModel($id, $model);
            $key = is_string($c['api_key'] ?? null) ? trim($c['api_key']) : '';
            $ready = ($c['enabled'] ?? false) === true && ($c['free_tier_confirmed'] ?? false) === true
                && $key !== '' && !preg_match('/[\x00-\x20\x7f]/', $key) && $validModel;
            $out[] = ['id'=>$id, 'label'=>$provider['label'], 'model'=>$model, 'available'=>$ready];
        }
        return $out;
    }

    public static function validModel(string $id, string $model): bool
    {
        if (!isset(self::catalog()[$id]) || $model === '' || strlen($model) > 120) return false;
        if ($id === 'gemini') return (bool)preg_match('/\A[a-zA-Z0-9._-]+\z/', $model);
        if (!preg_match('/\A[a-zA-Z0-9._:\/-]+\z/', $model)) return false;
        // OpenRouter has paid models too. This integration will not use one.
        return $id !== 'openrouter' || $model === 'openrouter/free' || str_ends_with($model, ':free');
    }

    /** Prepare an explicit request after input/provider validation; no network call occurs here. */
    public function prepare(string $id, string $title, string $brief, string $facts): array
    {
        $configured = null;
        foreach ($this->status() as $status) if ($status['id'] === $id && $status['available']) $configured = $status;
        if ($configured === null) throw new RuntimeException('این سرویس فعال یا آماده نیست؛ تنظیمات خصوصی و سهمیه رایگان آن را بررسی کنید.');
        $title = trim($title); $brief = trim($brief); $facts = trim($facts);
        if ($title === '' || mb_strlen($title) > 255 || mb_strlen($brief) > 2500 || mb_strlen($facts) > 8000) {
            throw new RuntimeException('عنوان لازم است؛ طول عنوان، درخواست یا اطلاعات منبع بیش از حد مجاز است.');
        }
        $prompt = "برای یک مقاله فارسی پیش‌نویس بنویس. از متن ساده و در صورت نیاز تیترهای ## استفاده کن؛ HTML ننویس. "
            . "فقط از اطلاعات تأییدشده‌ای که در پایین آمده برای ادعاهای مشخص درباره کسب‌وکارها استفاده کن. "
            . "نام، نشانی، تلفن، قیمت، ساعت، رتبه، ویژگی یا منبعی را حدس نزن و نساز. "
            . "اگر اطلاعات کافی نیست توضیح عمومی بنویس و نیاز به بررسی را مشخص کن. خروجی باید قبل از انتشار توسط انسان بررسی شود.\n\n"
            . "عنوان: {$title}\nراهنمای نویسنده: {$brief}\nاطلاعاتی که نویسنده ارائه کرده: {$facts}";
        $c = $this->config['providers'][$id];
        $adapter = $this->providers[$id]['adapter'];
        return ['id'=>$id, 'label'=>$configured['label'], 'model'=>$configured['model'], 'adapter'=>$adapter,
            'request'=>$adapter->request($configured['model'], trim((string)$c['api_key']), $prompt)];
    }

    /** A small explicit real generation verifies the configured model, not just DNS. */
    public function prepareConnectionTest(string $id): array
    {
        $prepared = $this->prepare($id, 'آزمایش اتصال', 'فقط واژه سلام را بنویس.', '');
        if ($id === 'gemini') {
            // Gemini 3 Flash-Lite uses some output tokens for thinking, even for simple requests.
            // A short test prompt with a bounded but less restrictive limit avoids false empty responses.
            $prepared['request']['body']['contents'] = [['role'=>'user', 'parts'=>[['text'=>'Reply with OK.']]]];
            $prepared['request']['body']['generationConfig']['maxOutputTokens'] = 256;
        } else {
            $prepared['request']['body']['max_tokens'] = 64;
        }
        return $prepared;
    }

    /** @param callable|null $transport Test seam; production always uses the fixed HTTPS transport. */
    public function send(array $prepared, ?callable $transport = null): string
    {
        $request = $prepared['request'];
        $result = $transport ? $transport($request) : self::postJson($request);
        if (!is_array($result) || !isset($result['status'], $result['body'])) throw new RuntimeException('پاسخ سرویس هوش مصنوعی نامعتبر بود.');
        $status = (int)$result['status'];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(self::providerError((string)($prepared['id'] ?? ''), $status, (string)$result['body']));
        }
        $decoded = json_decode((string)$result['body'], true);
        if (!is_array($decoded)) throw new RuntimeException('پاسخ سرویس هوش مصنوعی قابل خواندن نبود.');
        $text = trim($prepared['adapter']->text($decoded));
        if ($text === '' && ($prepared['id'] ?? '') === 'gemini' && ($decoded['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') {
            throw new RuntimeException('سرویس پاسخ داد، اما خروجی پیش از تولید متن به سقف توکن رسید (MAX_TOKENS).');
        }
        if ($text === '') throw new RuntimeException('سرویس متن قابل استفاده‌ای برنگرداند؛ ممکن است خروجی مسدود شده باشد.');
        return mb_substr($text, 0, 18000);
    }

    /** Display only a status and fixed guidance; provider bodies may contain credentials or prompts. */
    private static function providerError(string $provider, int $status, string $body): string
    {
        $hint = '';
        if ($provider === 'gemini' && $status === 400) {
            $data = json_decode($body, true);
            $details = is_array($data) && is_array($data['error']['details'] ?? null) ? $data['error']['details'] : [];
            foreach ($details as $detail) {
                if (is_array($detail) && ($detail['reason'] ?? '') === 'API_KEY_INVALID') {
                    $hint = 'کلید ارسال‌شده توسط Google معتبر شناخته نشد؛ کلید و پروژه را در پنل بررسی کنید.';
                    break;
                }
            }
            if ($hint === '' && is_array($data) && ($data['error']['status'] ?? '') === 'FAILED_PRECONDITION') {
                $hint = 'پیش‌شرط حساب، پروژه یا منطقه برقرار نیست؛ وضعیت دسترسی و طرح را در Google AI Studio بررسی کنید.';
            }
        }
        if ($hint === '') {
            $hint = match ($status) {
                400 => 'درخواست پذیرفته نشد؛ شناسه دقیق مدل، تنظیمات پروژه و کلید را بررسی کنید.',
                401, 403 => 'سرویس اجازه دسترسی نداد؛ کلید، محدودیت‌های آن، منطقه و تنظیمات حساب را بررسی کنید.',
                402 => 'سرویس وضعیت پرداخت یا اعتبار حساب را رد کرد؛ طرح و موجودی حساب را بررسی کنید.',
                404 => 'مدل یا نشانی آن برای این حساب پیدا نشد؛ نام دقیق مدل و دسترسی پروژه را بررسی کنید.',
                429 => 'سهمیه یا محدودیت این سرویس تمام شده است؛ سهمیه را بررسی کنید یا بعداً تلاش کنید.',
                500, 502, 503, 504 => 'سرویس یا مسیر ارتباطی موقتاً خطا داد؛ کمی بعد دوباره بررسی کنید.',
                default => 'سرویس پاسخ موفق نداد؛ تنظیمات مدل، حساب و وضعیت سرویس را بررسی کنید.',
            };
        }
        $code = $status >= 100 && $status <= 599 ? " (HTTP {$status})" : '';
        return 'پاسخ سرویس' . $code . ': ' . $hint;
    }

    private static function postJson(array $request): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
        $ch = curl_init($request['url']);
        if ($ch === false) throw new RuntimeException('اتصال به سرویس هوش مصنوعی آماده نشد.');
        $response = ''; $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>$request['headers'],
            CURLOPT_POSTFIELDS=>json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>45,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; }
                $response .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        // Do not expose cURL errors: they can contain URLs or other sensitive details.
        if ($ok === false || $tooLarge) throw new RuntimeException('ارتباط با سرویس ناموفق یا زمان آن تمام شد؛ وضعیت شبکه را بررسی کنید.');
        return ['status'=>$code, 'body'=>$response];
    }
}
