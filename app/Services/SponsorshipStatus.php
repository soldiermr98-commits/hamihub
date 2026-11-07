<?php
declare(strict_types=1);

namespace App\Services;

/** Classifies the currently stored article item, not a private draft revision. */
final class SponsorshipStatus
{
    /**
     * Match the public article: the article and business must both be published,
     * the start is inclusive, and the end is exclusive. $now is site local time.
     */
    public static function classify(array $item, string $now): string
    {
        if (($item['article_status'] ?? '') !== 'published') return 'article_unpublished';
        if (($item['business_status'] ?? '') !== 'published') return 'business_unpublished';
        if (!empty($item['sponsor_end']) && (string)$item['sponsor_end'] <= $now) return 'ended';
        if (!empty($item['sponsor_start']) && (string)$item['sponsor_start'] > $now) return 'upcoming';
        return 'active';
    }
}
