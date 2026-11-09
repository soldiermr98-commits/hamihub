<?php
declare(strict_types=1);

/*
 * Read-only, partial check for the published RC2 candidate and its .5 baseline.
 * Run from CLI only, on an isolated staging copy. This is NOT an installer.
 * It never loads the application, configuration, database or private files.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

// Do not print file-system errors or absolute paths to the report.
error_reporting(0);
ini_set('display_errors', '0');

const RC2_BASELINE_SHA256 = '5b9eb0e0e44c6eed48ebe29fcf404c4c921fd4d354b9accbb813a8bfd6984330';
const RC2_ARCHIVE_SHA256 = 'fee0a305fe6475ef9f87f0e382fc790dad342351ed586ee4555033c0eb38ce33';
const RC2_ARCHIVE_SIZE = 235821;
const RC2_BASELINE_FILES = 80;
const MAX_BASELINE_MANIFEST_BYTES = 65536;
const MAX_RUNTIME_FILE_BYTES = 67108864;

function emitReport(array $report, int $exitCode): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

function cliEnvironment(): array
{
    $extensions = [];
    foreach (['pdo_mysql', 'mbstring', 'curl', 'openssl', 'json', 'session', 'fileinfo', 'gd'] as $name) {
        $extensions[$name] = extension_loaded($name);
    }
    $webp = $extensions['gd'] && function_exists('imagewebp') && function_exists('imagecreatefromwebp')
        && function_exists('imagetypes') && defined('IMG_WEBP') && ((imagetypes() & IMG_WEBP) !== 0);
    return [
        'php_version' => PHP_VERSION,
        'php_at_least_8_1' => PHP_VERSION_ID >= 80100,
        'extensions' => $extensions,
        'gd_reports_webp_support' => $webp,
        'scope' => 'CLI only; web PHP and actual upload behavior are not checked',
    ];
}

function localArtifact(string $path, string $root, string $expectedName): ?string
{
    if ($path === '' || strpos($path, '://') !== false || basename($path) !== $expectedName || is_link($path)) return null;
    $resolved = @realpath($path);
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    // Artifacts are supplied outside the site, so a mistaken argument cannot
    // make this tool read an application config, key or upload as an artifact.
    if ($resolved === false || strpos($resolved, $prefix) === 0 || !is_file($resolved) || !is_readable($resolved)) return null;
    return $resolved;
}

/** Only accept safe, public runtime paths from the pinned published manifest. */
function parseManifest(string $manifest): ?array
{
    $entries = [];
    $lines = explode("\n", $manifest);
    foreach ($lines as $line) {
        if ($line === '') continue;
        if (!preg_match('/\A([a-f0-9]{64})  ([A-Za-z0-9._\/-]+)\z/', $line, $matches)) return null;
        $path = $matches[2];
        $parts = explode('/', $path);
        if (count($parts) < 2 || !in_array($parts[0], ['app', 'database', 'public_html'], true)) return null;
        foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..') return null;
        if (strpos($path, 'public_html/uploads/') === 0 || strpos($path, 'public_html/cache/') === 0) return null;
        if (isset($entries[$path])) return null;
        $entries[$path] = $matches[1];
    }
    return count($entries) === RC2_BASELINE_FILES ? $entries : null;
}

/** Return an issue without printing file content, hashes or the absolute root. */
function inspectRuntimeFile(string $root, string $relativePath, string $expectedHash): ?string
{
    $parts = explode('/', $relativePath);
    $path = $root;
    $last = count($parts) - 1;
    foreach ($parts as $index => $part) {
        $path .= DIRECTORY_SEPARATOR . $part;
        clearstatcache(true, $path);
        if (is_link($path)) return 'symlink_not_followed';
        $stat = @lstat($path);
        if ($stat === false) return 'missing_or_unavailable';
        $type = $stat['mode'] & 0170000;
        if ($index < $last && $type !== 0040000) return 'not_a_directory';
        if ($index === $last && $type !== 0100000) return 'not_a_regular_file';
    }
    $resolved = @realpath($path);
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($resolved === false || strpos($resolved, $prefix) !== 0 || !is_readable($resolved)) return 'unreadable_or_outside_root';
    $size = @filesize($resolved);
    if ($size === false || $size > MAX_RUNTIME_FILE_BYTES) return 'unreadable_or_too_large';
    $actualHash = @hash_file('sha256', $resolved);
    if (!is_string($actualHash)) return 'unreadable';
    return hash_equals($expectedHash, $actualHash) ? null : 'different';
}

if ($argc === 2 && $argv[1] === '--help') {
    echo "Usage: php rc2_read_only_preflight.php SITE_ROOT BASELINE_SHA256.txt RC2.zip\n";
    echo "CLI-only, read-only partial check. A matching report is NOT permission to deploy.\n";
    exit(0);
}
if ($argc !== 4) {
    fwrite(STDERR, "Expected SITE_ROOT, BASELINE_SHA256.txt and RC2.zip. Use --help.\n");
    exit(64);
}
if (!function_exists('json_encode') || !defined('JSON_PRETTY_PRINT')) {
    fwrite(STDERR, "The CLI JSON extension is required; no site files were read.\n");
    exit(69);
}
foreach (['hash', 'hash_file', 'hash_equals'] as $requiredFunction) {
    if (!function_exists($requiredFunction)) {
        fwrite(STDERR, "The CLI hash functions are required; no site files were read.\n");
        exit(69);
    }
}

$report = [
    'tool' => 'rc2-read-only-preflight-v1',
    'decision' => 'STOP',
    'archive_verified' => false,
    'baseline_manifest_verified' => false,
    'baseline' => null,
    'cli_environment' => cliEnvironment(),
    'errors' => [],
    'not_checked' => [
        'web PHP version and extensions',
        'database schema, migrations and existing receipts',
        'backups and recovery',
        'extra or customized files outside the baseline list',
        'cron, external services, payments and real hosting',
    ],
];

// Resolve only the root directory first, then require both artifacts outside it.
$rootInput = $argv[1];
$rootArgument = rtrim($rootInput, DIRECTORY_SEPARATOR);
if ($rootArgument === '') $rootArgument = DIRECTORY_SEPARATOR;
$root = $rootInput !== '' && strpos($rootInput, '://') === false && !is_link($rootArgument)
    ? @realpath($rootArgument) : false;
if ($root === false || $root === DIRECTORY_SEPARATOR || !is_dir($root)) {
    $report['errors'][] = 'site_root_unavailable';
    emitReport($report, 2);
}

// Verify both published artifacts before reading any site runtime files.
$manifestFile = localArtifact($argv[2], $root, 'BASELINE-SHA256.txt');
if ($manifestFile === null || ($manifestSize = @filesize($manifestFile)) === false
    || $manifestSize > MAX_BASELINE_MANIFEST_BYTES) {
    $report['errors'][] = 'baseline_manifest_unavailable';
    emitReport($report, 2);
}
$manifest = @file_get_contents($manifestFile);
if (!is_string($manifest) || !hash_equals(RC2_BASELINE_SHA256, hash('sha256', $manifest))) {
    $report['errors'][] = 'baseline_manifest_digest_mismatch';
    emitReport($report, 2);
}
$entries = parseManifest($manifest);
if ($entries === null) {
    $report['errors'][] = 'baseline_manifest_invalid';
    emitReport($report, 2);
}
$report['baseline_manifest_verified'] = true;

$archiveFile = localArtifact($argv[3], $root, 'hamihub-update-after-v2026.11.07.5-rc2.zip');
if ($archiveFile === null || @filesize($archiveFile) !== RC2_ARCHIVE_SIZE) {
    $report['errors'][] = 'rc2_archive_unavailable_or_wrong_size';
    emitReport($report, 2);
}
$archiveHash = @hash_file('sha256', $archiveFile);
if (!is_string($archiveHash) || !hash_equals(RC2_ARCHIVE_SHA256, $archiveHash)) {
    $report['errors'][] = 'rc2_archive_digest_mismatch';
    emitReport($report, 2);
}
$report['archive_verified'] = true;

$matched = 0;
$issues = [];
foreach ($entries as $path => $expectedHash) {
    $issue = inspectRuntimeFile($root, $path, $expectedHash);
    if ($issue === null) $matched++;
    else $issues[] = ['path' => $path, 'issue' => $issue];
}
$report['baseline'] = ['expected' => RC2_BASELINE_FILES, 'matched' => $matched, 'issues' => $issues];
if ($issues !== []) {
    $report['errors'][] = 'baseline_not_exact';
    emitReport($report, 2);
}

// Even 80/80 is only a partial result. DB, backups, customization and web PHP
// require independent checks and an explicit human deployment decision.
$report['decision'] = 'MANUAL_REVIEW_REQUIRED';
emitReport($report, 0);
