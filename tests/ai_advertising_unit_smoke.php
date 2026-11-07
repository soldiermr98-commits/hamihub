<?php
declare(strict_types=1);

// Pure synthetic adapter and validation checks; never calls a real provider or database.
require dirname(__DIR__) . '/app/Services/AiArticleService.php';
require dirname(__DIR__) . '/app/Services/AdvertisingService.php';
use App\Services\AiArticleService;
use App\Services\AdvertisingService;
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejects(callable $f, string $message): void { try { $f(); } catch (RuntimeException $e) { return; } throw new RuntimeException($message); }
$config = ['providers'=>[
    'gemini'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-gemini-secret','model'=>'gemini-test-model'],
    'groq'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-groq-secret','model'=>'test/model'],
    'openrouter'=>['enabled'=>true,'free_tier_confirmed'=>true,'api_key'=>'test-router-secret','model'=>'openrouter/free'],
]];
$service = new AiArticleService($config);
$status = $service->status();
check(array_column($status, 'id') === ['gemini','groq','openrouter'], 'Provider order wrong');
check(count(array_filter($status, fn($p)=>$p['available'])) === 3, 'Ready providers missing');
check(!str_contains(json_encode($status), 'secret'), 'Provider status leaks keys');
$requests = [];
$fake = function (array $request) use (&$requests): array {
    $requests[] = $request;
    if (str_contains($request['url'], 'generativelanguage.googleapis.com')) return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>'پیشنهاد جمنای']]]]]], JSON_UNESCAPED_UNICODE)];
    return ['status'=>200,'body'=>json_encode(['choices'=>[['message'=>['content'=>'پیشنهاد مدل']]]], JSON_UNESCAPED_UNICODE)];
};
foreach (['gemini','groq','openrouter'] as $id) {
    $prepared = $service->prepare($id, 'عنوان تست', 'راهنمای تست', 'واقعیت تأییدشده');
    $text = $service->send($prepared, $fake);
    check($text !== '', 'Adapter failed to parse response');
}
check(count($requests) === 3, 'More than one call per explicit request');
check($requests[0]['url'] === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent', 'Gemini URL incorrect');
check($requests[1]['url'] === 'https://api.groq.com/openai/v1/chat/completions', 'Groq URL incorrect');
check($requests[2]['url'] === 'https://openrouter.ai/api/v1/chat/completions', 'OpenRouter URL incorrect');
check(!str_contains($requests[0]['url'], 'secret'), 'Key in URL');
check(str_contains(json_encode($requests[0]['body'], JSON_UNESCAPED_UNICODE), 'حدس نزن'), 'Fact warning missing');
$prepared = $service->prepare('gemini','عنوان','', '');
try { $service->send($prepared, fn()=>['status'=>429,'body'=>'test-gemini-secret']); throw new RuntimeException('Quota should be rejected'); }
catch (RuntimeException $e) { check(!str_contains($e->getMessage(), 'secret') && str_contains($e->getMessage(),'سهمیه'), 'Unsafe quota error'); }
$config['providers']['openrouter']['model'] = 'provider/paid-model';
check(!(new AiArticleService($config))->status()[2]['available'], 'Paid OpenRouter model was accepted');
$config['providers']['gemini']['free_tier_confirmed'] = false;
check(!(new AiArticleService($config))->status()[0]['available'], 'Unconfirmed account was accepted');
rejects(fn()=> $service->prepare('gemini','','',''), 'Empty title accepted');
foreach (['javascript:alert(1)','//bad.example/','http://bad.example/',"https://good.example/\nLocation: bad",'https://user@bad.example/'] as $url) check(!AdvertisingService::validTarget($url), 'Unsafe ad URL accepted: ' . $url);
foreach (['https://example.com/','/city/rasht/'] as $url) check(AdvertisingService::validTarget($url), 'Valid ad URL rejected');
check(!AdvertisingService::validImagePath('/uploads/../evil.webp'), 'Path traversal accepted');
check(AdvertisingService::validImagePath('/uploads/2026/11/img_abc_1200.webp'), 'Normal media path rejected');
echo "PASS: synthetic provider adapters, free-model restrictions, consent prerequisites and safe ad URL validation.\n";
