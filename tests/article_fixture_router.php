<?php
declare(strict_types=1);

// Disposable PHP built-in server only. It forces every request onto the
// explicitly designated local test database and exposes a nonce to the test.
$configFile = getenv('HHX_TEST_CONFIG') ?: '';
$nonce = getenv('HHX_TEST_NONCE') ?: '';
if (PHP_SAPI !== 'cli-server' || getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile) || strlen($nonce) < 16) {
    http_response_code(403); exit('Test-only server; missing explicit fixture guards.');
}
$config = require $configFile;
$db = is_array($config) ? ($config['db'] ?? []) : [];
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', (string)($db['database'] ?? '')) || !in_array((string)($db['host'] ?? ''), ['localhost','127.0.0.1'], true)) {
    http_response_code(403); exit('Refusing non-local or non-test database.');
}
$base = dirname(__DIR__);
require $base . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
$root = $base . '/public_html';
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($path === '/__article_fixture_guard__') {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['nonce'=>$nonce,'database'=>$db['database']]); exit;
}
if (str_starts_with($path, '/cache/')) { http_response_code(403); exit; }
$file = realpath($root . $path);
if ($file && str_starts_with($file, $root . '/') && is_file($file)) return false;
if (str_starts_with($path, '/api/')) { require $root . '/api/index.php'; return true; }
require $root . '/index.php';
return true;
