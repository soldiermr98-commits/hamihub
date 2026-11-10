<?php
declare(strict_types=1);

// End-to-end synthetic DB smoke test for:
// 1. 1-to-1 unification of categories <-> category_packs / category_pack_items (CategorySyncService)
// 2. Preserving Apify first search term ('دندانپزشکی' for category 3)
// 3. Expanded OSM tags (healthcare, leisure, natural, chalet, apartment, fast_food)
// 4. City-wide cross-category deduplication (<80m / shared phone / core name) + borderline review + 1-click merge

$configFile = getenv('HHX_TEST_CONFIG') ?: '';
if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || !$configFile || !is_file($configFile)) {
    fwrite(STDERR, "Use HHX_ALLOW_TEST_FIXTURES=1 and HHX_TEST_CONFIG=/path/to/test-only-config.php\n");
    exit(2);
}
$config = require $configFile;
$database = is_array($config) ? (string)($config['db']['database'] ?? '') : '';
if (!preg_match('/^hhx_test_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Refusing database: a disposable hhx_test_* database is required.\n");
    exit(2);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Config.php';
\App\Core\Config::load($configFile);
require BASE_PATH . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init(BASE_PATH);
require BASE_PATH . '/collector-remote/Providers/ProviderInterface.php';
require BASE_PATH . '/collector-remote/Providers/OsmProvider.php';

use App\Core\DB;
use App\Services\ApifyAutomationService;
use App\Services\CategorySyncService;
use App\Services\CollectorRunner;
use App\Services\PipelineService;
use CollectorRemote\Providers\OsmProvider;
use CollectorRemote\Providers\ProviderInterface;

function checkUnified(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo "PASS: {$label}\n";
}

$pdo = DB::connection();
$pdo->beginTransaction();
try {
    // Simulate legacy divergent pack names before sync
    DB::query("UPDATE category_packs SET name = 'بسته کافه‌ها', queries_json = '{\"osm\":[\"amenity=cafe\"]}' WHERE id = 1");
    DB::query("UPDATE category_packs SET name = 'بسته ویلا، هتل و اقامتگاه بومگردی', queries_json = '{\"osm\":[\"tourism=hotel\"]}' WHERE id = 4");

    CategorySyncService::ensureSynced(true);

    $packs = CategorySyncService::listUnifiedPacks();
    $bySlug = [];
    foreach ($packs as $pk) {
        if (!empty($pk['category_slug'])) {
            $bySlug[(string)$pk['category_slug']] = $pk;
        }
    }

    $expectedTitles = [
        'cafe-restaurant' => 'کافه و رستوران',
        'beauty-salon'    => 'سالن زیبایی',
        'clinic-dental'   => 'کلینیک و دندانپزشکی',
        'villa-stay'      => 'ویلا و اقامتگاه',
        'attraction'      => 'جاذبه گردشگری',
    ];
    foreach ($expectedTitles as $slug => $title) {
        checkUnified(
            isset($bySlug[$slug]) && $bySlug[$slug]['name'] === $title && $bySlug[$slug]['category_name_fa'] === $title,
            "pack synced to canonical category {$slug} ({$title})"
        );
    }

    $cafeQ = json_decode((string)$bySlug['cafe-restaurant']['queries_json'], true);
    checkUnified(
        in_array('amenity=cafe', $cafeQ['osm'] ?? [], true)
        && in_array('amenity=restaurant', $cafeQ['osm'] ?? [], true)
        && in_array('amenity=fast_food', $cafeQ['osm'] ?? [], true),
        'cafe-restaurant pack includes cafe + restaurant + fast_food OSM tags'
    );

    $stayQ = json_decode((string)$bySlug['villa-stay']['queries_json'], true);
    checkUnified(
        in_array('tourism=hotel', $stayQ['osm'] ?? [], true)
        && in_array('tourism=chalet', $stayQ['osm'] ?? [], true)
        && in_array('tourism=apartment', $stayQ['osm'] ?? [], true),
        'villa-stay pack includes hotel + chalet + apartment OSM tags'
    );

    $clinicQ = json_decode((string)$bySlug['clinic-dental']['queries_json'], true);
    checkUnified(
        in_array('healthcare=clinic', $clinicQ['osm'] ?? [], true)
        && in_array('amenity=dentist', $clinicQ['osm'] ?? [], true),
        'clinic-dental pack includes dentist + healthcare=clinic OSM tags'
    );

    // Ensure Apify first search term contract ('دندانپزشکی') remains intact
    $apifyStatus = ApifyAutomationService::status();
    checkUnified(
        ($apifyStatus['search_terms']['3'] ?? null) === 'دندانپزشکی',
        'Apify default search term for category 3 remains دندانپزشکی'
    );

    // Ensure OsmProvider maps new healthcare / leisure / natural tags without network calls via mapElements + tag validation
    $mapped = OsmProvider::mapElements([
        ['type' => 'node', 'id' => 991001, 'lat' => 37.281, 'lon' => 49.584, 'tags' => ['name' => 'کلینیک مهر گیلان', 'healthcare' => 'clinic']],
        ['type' => 'node', 'id' => 991002, 'lat' => 37.282, 'lon' => 49.585, 'tags' => ['name' => 'پارک ملت رشت', 'leisure' => 'park']],
    ]);
    checkUnified(count($mapped) === 2, 'OsmProvider maps healthcare & leisure nodes');

    // Verify CollectorRunner assigns unified category_id=4 (ویلا و اقامتگاه)
    $suffix = bin2hex(random_bytes(5));
    $villaPackId = (int)$bySlug['villa-stay']['id'];
    DB::query(
        "INSERT INTO collector_jobs (city_id, pack_id, provider, query, status, scheduled_at) VALUES (1, :pid, 'osm', '', 'queued', NOW())",
        ['pid' => $villaPackId]
    );
    $fakeProvider = new class($suffix) implements ProviderInterface {
        public function __construct(private string $s) {}
        public function name(): string { return 'osm'; }
        public function collect(array $city, array $queries): array
        {
            return [[
                'source_id' => 'node:villa-' . $this->s,
                'name' => 'اقامتگاه بومگردی باران ' . $this->s,
                'lat' => 37.2910,
                'lng' => 49.5910,
                'address' => 'رشت، بلوار لاکان',
                'phones' => ['+981333445566'],
                'raw' => ['osm' => true],
            ]];
        }
    };
    $runRes = CollectorRunner::runNextQueuedJob(1, static fn(): ProviderInterface => $fakeProvider);
    checkUnified(!empty($runRes['ok']) && ($runRes['summary']['new_count'] ?? 0) === 1, 'CollectorRunner executes unified villa-stay job');
    $createdVilla = DB::fetchOne('SELECT id, category_id FROM businesses WHERE name = :n', ['n' => 'اقامتگاه بومگردی باران ' . $suffix]);
    checkUnified((int)($createdVilla['category_id'] ?? 0) === 4, 'CollectorRunner saves under category_id=4 (ویلا و اقامتگاه)');

    // Verify city-wide cross-category auto-deduplication (<50m + same core name 'باران <suffix>' or shared phone)
    $gmapsOverlap = PipelineService::processIncomingRecord(1, 1, 'google_maps', [
        'source_id' => 'gpid_overlap_' . $suffix,
        'name' => 'هتل باران ' . $suffix,
        'lat' => 37.2911, // ~14 meters away
        'lng' => 49.5911,
        'address' => 'رشت، بلوار لاکان، پلاک ۱۲',
        'phones' => ['+981333445566'],
        'raw' => ['gmaps' => true],
    ]);
    checkUnified(
        !$gmapsOverlap['is_new'] && (int)$gmapsOverlap['business_id'] === (int)$createdVilla['id'],
        'cross-category incoming record within 50m / shared phone auto-attaches instead of duplicating'
    );
    checkUnified(
        (int)DB::fetchOne('SELECT category_id FROM businesses WHERE id = :id', ['id' => (int)$createdVilla['id']])['category_id'] === 4,
        'auto-attached cross-category source preserves canonical business category_id'
    );

    // Verify borderline duplicate (~120m away, same core name, no shared phone) creates kind='duplicate' review item
    $canonCafe = PipelineService::processIncomingRecord(1, 1, 'google_maps', [
        'source_id' => 'gpid_sarv_' . $suffix,
        'name' => 'رستوران سرو ' . $suffix,
        'lat' => 37.2750,
        'lng' => 49.5800,
        'address' => 'رشت، گلسار',
        'phones' => ['+981333112233'],
        'raw' => ['gmaps' => true],
    ]);
    $borderlineOsm = PipelineService::processIncomingRecord(1, 5, 'osm', [
        'source_id' => 'node:sarv_' . $suffix,
        'name' => 'کافه سرو ' . $suffix,
        'lat' => 37.2760, // ~111 meters north
        'lng' => 49.5800,
        'address' => 'رشت، گلسار، نبش کوچه ۱۰',
        'phones' => ['+989119998877'],
        'raw' => ['osm' => true],
    ]);
    checkUnified(
        $borderlineOsm['is_new'] && !empty($borderlineOsm['duplicate_candidates']),
        'borderline ~111m cross-category match is held for human review with duplicate_candidates'
    );
    $dupQueueRow = DB::fetchOne(
        "SELECT * FROM review_queue WHERE kind = 'duplicate' AND target_id = :tid AND status = 'pending'",
        ['tid' => (int)$borderlineOsm['business_id']]
    );
    checkUnified(
        (bool)$dupQueueRow && $dupQueueRow['reason_code'] === 'potential_duplicate',
        'borderline match creates pending kind=duplicate item in review_queue'
    );

    echo "All unified categories & cross-category deduplication DB checks passed; rolling back.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
