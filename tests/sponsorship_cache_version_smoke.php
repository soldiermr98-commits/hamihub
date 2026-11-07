<?php
declare(strict_types=1);

// Tests only files created under a unique temporary directory. No database/site data.
$root = sys_get_temp_dir() . '/hhx-sponsor-cache-' . bin2hex(random_bytes(8));
if (!mkdir($root . '/public_html/cache/pages', 0700, true)) throw new RuntimeException('Cannot create temporary cache directory.');
define('BASE_PATH', $root);
require_once dirname(__DIR__) . '/app/Core/Cache.php';
use App\Core\Cache;
try {
    if (!Cache::putPage('/old-version', '<link href="/assets/css/public.css?v=s9.0">')) throw new RuntimeException('Could not write old fixture.');
    if (Cache::getPage('/old-version') !== null) throw new RuntimeException('Outdated cached page was served.');
    if (!Cache::putPage('/new-version', '<link href="/assets/css/public.css?v=s9.1">')) throw new RuntimeException('Could not write current fixture.');
    if (Cache::getPage('/new-version') === null) throw new RuntimeException('Current cached page was ignored.');
    echo "PASS: the sponsorship update ignores old cached pages and accepts the new cache version.\n";
} finally {
    Cache::flushAll();
    @rmdir($root . '/public_html/cache/pages');
    @rmdir($root . '/public_html/cache');
    @rmdir($root . '/public_html');
    @rmdir($root);
}
