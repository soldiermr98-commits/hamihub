<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

use RuntimeException;

/**
 * پرووایدر OpenStreetMap / Overpass API (با TIMEOUT=90 طبق اصلاحیه ۲)
 */
final class OsmProvider implements ProviderInterface
{
    private array $config;
    private int $timeout;

    public function __construct(array $config, int $timeout = 90)
    {
        $this->config = $config;
        $this->timeout = $timeout;
    }

    public function name(): string
    {
        return 'osm';
    }

    public function search(array $job): array
    {
        $bbox = isset($job['bbox']) && is_array($job['bbox']) && count($job['bbox']) === 4
            ? $job['bbox']
            : [37.1900, 49.4800, 37.3600, 49.6800];

        [$south, $west, $north, $east] = [
            (float) $bbox[0],
            (float) $bbox[1],
            (float) $bbox[2],
            (float) $bbox[3],
        ];

        $queries = isset($job['queries']) && is_array($job['queries']) ? $job['queries'] : ['amenity=cafe'];
        $overpassFilters = [];

        foreach ($queries as $q) {
            $qStr = trim((string) $q);
            if (str_contains($qStr, '=')) {
                [$k, $v] = array_map('trim', explode('=', $qStr, 2));
                $overpassFilters[] = sprintf('nwr["%s"="%s"](%F,%F,%F,%F);', $k, $v, $south, $west, $north, $east);
            } else {
                // اگر عبارت فارسی بود (مثلاً «کافه»)، هم تگ cafe/restaurant و هم نام را جستجو کند
                if (str_contains($qStr, 'کافه')) {
                    $overpassFilters[] = sprintf('nwr["amenity"="cafe"](%F,%F,%F,%F);', $south, $west, $north, $east);
                } elseif (str_contains($qStr, 'رستوران') || str_contains($qStr, 'کبابی')) {
                    $overpassFilters[] = sprintf('nwr["amenity"="restaurant"](%F,%F,%F,%F);', $south, $west, $north, $east);
                } else {
                    $overpassFilters[] = sprintf('nwr["name"~"%s"](%F,%F,%F,%F);', addslashes($qStr), $south, $west, $north, $east);
                }
            }
        }

        if (empty($overpassFilters)) {
            $overpassFilters[] = sprintf('nwr["amenity"="cafe"](%F,%F,%F,%F);', $south, $west, $north, $east);
        }

        $overpassQl = sprintf(
            "[out:json][timeout:%d];(\n%s\n);out center 200;",
            $this->timeout,
            implode("\n", $overpassFilters)
        );

        $endpoint = (string) ($this->config['overpass_endpoint'] ?? 'https://overpass-api.de/api/interpreter');
        $userAgent = (string) ($this->config['user_agent'] ?? 'HamiHubX-Collector/1.0');

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $overpassQl]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $userAgent,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            throw new RuntimeException(
                sprintf('خطا در فراخوانی Overpass API (HTTP %d): %s', $httpCode, $curlErr ?: 'پاسخ نامعتبر'),
                $httpCode ?: 500
            );
        }

        $decoded = json_decode((string) $raw, true);
        $elements = isset($decoded['elements']) && is_array($decoded['elements']) ? $decoded['elements'] : [];

        $results = [];
        $cityName = (string) ($job['city_name_fa'] ?? 'رشت');

        foreach ($elements as $el) {
            $tags = isset($el['tags']) && is_array($el['tags']) ? $el['tags'] : [];
            $name = trim((string) ($tags['name:fa'] ?? $tags['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $lat = isset($el['lat']) ? (float) $el['lat'] : (isset($el['center']['lat']) ? (float) $el['center']['lat'] : null);
            $lng = isset($el['lon']) ? (float) $el['lon'] : (isset($el['center']['lon']) ? (float) $el['center']['lon'] : null);

            $street = trim((string) ($tags['addr:street'] ?? ''));
            $fullAddr = trim((string) ($tags['addr:full'] ?? ''));
            $address = $fullAddr !== ''
                ? $fullAddr
                : ($street !== '' ? "{$cityName}، {$street}" : "{$cityName}");

            $phones = [];
            foreach (['phone', 'contact:phone', 'mobile', 'contact:mobile'] as $pk) {
                if (!empty($tags[$pk])) {
                    $parts = preg_split('/[;,\/]+/', (string) $tags[$pk]) ?: [];
                    foreach ($parts as $p) {
                        if (trim($p) !== '') {
                            $phones[] = trim($p);
                        }
                    }
                }
            }

            $results[] = [
                'name' => $name,
                'category' => (string) ($tags['amenity'] ?? $tags['shop'] ?? $tags['tourism'] ?? 'general'),
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'phones' => $phones,
                'hours' => trim((string) ($tags['opening_hours'] ?? '')),
                'rating' => null,
                'reviews_count' => 0,
                'photo_urls' => [],
                'source' => 'osm',
                'source_id' => (string) ($el['type'] ?? 'node') . '/' . (string) ($el['id'] ?? ''),
            ];
        }

        return $results;
    }

    public function details(string $sourceId): ?array
    {
        return null;
    }
}
