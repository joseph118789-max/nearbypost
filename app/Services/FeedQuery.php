<?php

namespace App\Services;

use App\Models\FeedReadyItem;
use Illuminate\Support\Facades\DB;

/**
 * Canonical feed queries.
 *
 * The server-rendered pages and the JSON API must not drift apart, so both read
 * the feed through this one class rather than each carrying its own SQL.
 *
 * Everything here reads feed_ready_items, the serving layer. Nothing touches
 * news_items: promotion into the serving layer is the pipeline's job and
 * carries its own gates.
 */
class FeedQuery
{
    public const DEFAULT_WINDOW_DAYS = 7;

    /** Columns every feed surface returns. */
    public function fields(): array
    {
        return [
            'id', 'title', 'summary', 'source', 'published_at',
            'primary_category', 'secondary_category', 'sub_category',
            'url', 'lat', 'lng', 'location_label', 'precision_type',
        ];
    }

    /**
     * Newest first, then better-formed items.
     * location_and_category > location_only > category_only
     */
    public function defaultOrderBy(string $table = 'feed_ready_items'): string
    {
        $case = "CASE {$table}.relevance_mode"
            . " WHEN 'location_and_category' THEN 1"
            . " WHEN 'location_only' THEN 2"
            . " WHEN 'category_only' THEN 3"
            . ' ELSE 4 END';

        return "published_at DESC, {$case} ASC, {$table}.id DESC";
    }

    /** Latest stories, optionally within a category. */
    public function latest(?string $category = null, int $days = self::DEFAULT_WINDOW_DAYS, int $limit = 20): array
    {
        $query = FeedReadyItem::where('is_active', true)
            ->where('published_at', '>=', now()->subDays($days));

        if ($category !== null && $category !== '' && $category !== 'all') {
            // Categories are stored lower-case; accept any casing from the URL.
            $query->whereRaw('LOWER(primary_category) = ?', [mb_strtolower($category)]);
        }

        return $query->orderByRaw($this->defaultOrderBy())
            ->limit($limit)
            ->get($this->fields())
            ->map(fn ($row) => $row->toArray())
            ->all();
    }

    /**
     * Stories near a point, nearest first, within the time window.
     *
     * The window matters: without it the query returns the closest stories of
     * all time, and a large back catalogue permanently outranks this week.
     */
    public function nearby(
        float $lat,
        float $lng,
        float $radiusKm,
        int $days = self::DEFAULT_WINDOW_DAYS,
        int $limit = 20,
        ?string $category = null
    ): array {
        $categoryClause = '';
        $bindings = [
            'lat'    => $lat,
            'lng'    => $lng,
            'radius' => $radiusKm,
            'days'   => $days,
        ];

        if ($category !== null && $category !== '' && $category !== 'all') {
            $categoryClause = ' AND LOWER(primary_category) = :category';
            $bindings['category'] = mb_strtolower($category);
        }

        $sql = "SELECT * FROM (
            SELECT id, title, summary, source, published_at,
                   primary_category, secondary_category, sub_category,
                   url, location_label, lat, lng,
                   ROUND(
                       (6371.0 * acos(
                           LEAST(1.0,
                               cos(radians(:lat)) * cos(radians(lat)) *
                               cos(radians(lng) - radians(:lng)) +
                               sin(radians(:lat)) * sin(radians(lat))
                           )
                       ))::numeric, 2
                   ) AS distance_km
            FROM feed_ready_items
            WHERE is_active = true
              AND is_article = true
              AND lat IS NOT NULL
              AND lng IS NOT NULL
              AND relevance_mode != 'category_only'
              AND published_at >= NOW() - make_interval(days => :days)
              {$categoryClause}
        ) AS nearby
        WHERE distance_km <= :radius
        ORDER BY distance_km ASC, published_at DESC
        LIMIT {$limit}";

        return array_map(
            fn ($row) => (array) $row,
            DB::select($sql, $bindings)
        );
    }

    /** Distinct primary categories currently present in the feed. */
    public function categories(): array
    {
        // Fold case: the same category has been written both title-cased and
        // lower-cased, which would otherwise list "Crime & Safety" twice.
        return FeedReadyItem::where('is_active', true)
            ->whereNotNull('primary_category')
            ->selectRaw('DISTINCT LOWER(primary_category) AS c')
            ->orderBy('c')
            ->pluck('c')
            ->all();
    }
}
