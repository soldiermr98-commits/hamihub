<?php
declare(strict_types=1);

// Test-only fixed fake transport. This file is never included in a release bundle.
// The original fixture router below guards CLI-server, nonce, and a local hhx_test_* DB.
namespace App\Services {
    function curl_init(string $url): object|false {
        if (!str_starts_with($url, 'https://generativelanguage.googleapis.com/')
            && $url !== 'https://api.groq.com/openai/v1/chat/completions'
            && $url !== 'https://openrouter.ai/api/v1/chat/completions') return false;
        return (object)['url'=>$url, 'options'=>[], 'status'=>0];
    }
    function curl_setopt_array(object $curl, array $options): bool { $curl->options=$options; return true; }
    function curl_exec(object $curl): bool {
        $headers=$curl->options[CURLOPT_HTTPHEADER] ?? [];
        $body=json_decode((string)($curl->options[CURLOPT_POSTFIELDS] ?? ''), true);
        $gemini=str_starts_with($curl->url, 'https://generativelanguage.googleapis.com/');
        $expected=$gemini?'x-goog-api-key: synthetic-replacement-key':
            ($curl->url==='https://api.groq.com/openai/v1/chat/completions'?'Authorization: Bearer synthetic-file-only-key':'Authorization: Bearer synthetic-router-key');
        $limit=$gemini?($body['generationConfig']['maxOutputTokens'] ?? null):($body['max_tokens'] ?? null);
        $allowed=$gemini ? [256,2048] : [64,2048];
        $shortPrompt=!$gemini || $limit !== 256 || ($body['contents'][0]['parts'][0]['text'] ?? '') === 'Reply with OK.';
        $noTemperature=!$gemini || !array_key_exists('temperature', $body['generationConfig'] ?? []);
        $curl->status=in_array($expected,$headers,true) && in_array($limit,$allowed,true) && $shortPrompt && $noTemperature ? 200 : 401;
        if ($curl->status===200 && str_contains($curl->url, '/gemini-fixture-error404:')) {
            $curl->status=404;
            $response=['error'=>['status'=>'NOT_FOUND','message'=>'synthetic-provider-secret-must-not-leak']];
        } else {
            $response=$gemini?['candidates'=>[['content'=>['parts'=>[['text'=>'synthetic-test-response']]]]]]:['choices'=>[['message'=>['content'=>'synthetic-test-response']]]];
        }
        $callback=$curl->options[CURLOPT_WRITEFUNCTION] ?? null;
        if (!is_callable($callback)) return false;
        $chunk=json_encode($response);
        return is_string($chunk) && $callback($curl,$chunk) === strlen($chunk);
    }
    function curl_getinfo(object $curl, int $option): int { return $curl->status; }
}
namespace {
    define('HHX_TEST_FAKE_AI_TRANSPORT', true);
    require __DIR__ . '/article_fixture_router.php';
}
