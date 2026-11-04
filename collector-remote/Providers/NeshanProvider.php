<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

use RuntimeException;

/**
 * پرووایدر رسمی نقشه نشان (Neshan API با کلید رسمی در فایل تنظیمات)
 */
final class NeshanProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'neshan';
    }

    public function search(array $job): array
    {
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('کلید API رسمی نشان (neshan.api_key) در collector-remote/config.php تنظیم نشده است.', 401);
        }

        $lat = (float) ($job['lat'] ?? 37.2808);
        $lng = (float) ($job['lng'] ?? 49.5830);
        $cityName = (string) ($job['city_name_fa'] ?? 'رشت');
        $queries = isset($job['queries']) && is_array($job['queries']) ? $job['queries'] : ['کافه'];

        $searchUrl = (string) ($this->config['search_url'] ?? 'https://api.neshan.org/v1/search');
        $results = [];

        foreach ($queries as $q) {
            $term = trim((string) $q) . ' ' . $cityName;
            $url = sprintf('%s?term=%s&lat=%F&lng=%F', $searchUrl, rawurlencode($term), $lat, $lng);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => [
                    'Api-Key: ' . $apiKey,
                    'Accept: application/json',
                ],
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $httpCode >= 400) {
                throw new RuntimeException("خطای API نشان (HTTP {$httpCode}): {$err}", $httpCode ?: 500);
            }

            $decoded = json_decode((string) $raw, true);
            $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : [];

            foreach ($items as $it) {
                $title = trim((string) ($it['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $loc = $it['location'] ?? [];
                $itemLng = isset($loc['x']) ? (float) $loc['x'] : null;
                $itemLat = isset($loc['y']) ? (float) $loc['y'] : null;

                $results[] = [
                    'name' => $title,
                    'category' => (string) ($it['category'] ?? $q),
                    'address' => trim((string) ($it['address'] ?? $it['region'] ?? $cityName)),
                    'lat' => $itemLat,
                    'lng' => $itemLng,
                    'phones' => [],
                    'hours' => '',
                    'rating' => null,
                    'reviews_count' => 0,
                    'photo_urls' => [],
                    'source' => 'neshan',
                    'source_id' => 'neshan_' . md5($title . ':' . $itemLat . ':' . $itemLng),
                ];
            }
        }

        return $results;
    }

    public function details(string $sourceId): ?array
    {
        return null;
    }
}
