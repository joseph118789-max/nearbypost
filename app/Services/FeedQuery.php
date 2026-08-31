<?php

namespace App\Services;

use App\Models\FeedReadyItem;
use App\Support\Loc;
use Illuminate\Support\Facades\DB;

/**
 * Canonical feed queries.
 *
 * The server-rendered pages and the JSON API must not drift apart, so both read
 * the feed through this one class rather than each carrying its own SQL.
 *
 * Every query joins the translation for the reading language and falls back to
 * the original text when there is none. A story published in Tamil with no
 * Chinese translation yet still appears for a Chinese reader, in Tamil, rather
 * than vanishing from the feed - a missing translation should degrade what is
 * shown, not what exists.
 *
 * Everything here reads feed_ready_items, the serving layer. Nothing touches
 * news_items: promotion into the serving layer is the pipeline's job and carries
 * its own gates.
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
    public function latest(
        ?string $category = null,
        int $days = self::DEFAULT_WINDOW_DAYS,
        int $limit = 20,
        ?string $locale = null
    ): array {
        $locale = $locale ?? Loc::current();

        $query = FeedReadyItem::query()
            ->leftJoin('news_translations as t', function ($join) use ($locale) {
                $join->on('t.news_item_id', '=', 'feed_ready_items.news_item_id')
                     ->where('t.locale', '=', $locale);
            })
            ->where('feed_ready_items.is_active', true)
            ->where('feed_ready_items.published_at', '>=', now()->subDays($days));

        if ($category !== null && $category !== '' && $category !== 'all') {
            // Categories are stored lower-case; accept any casing from the URL.
            $query->whereRaw('LOWER(feed_ready_items.primary_category) = ?', [mb_strtolower($category)]);
        }

        $rows = $query
            ->orderByRaw($this->defaultOrderBy())
            ->limit($limit)
            ->get([
                'feed_ready_items.id',
                DB::raw('COALESCE(t.title, feed_ready_items.title) AS title'),
                DB::raw('COALESCE(t.summary, feed_ready_items.summary) AS summary'),
                'feed_ready_items.source',
                'feed_ready_items.published_at',
                'feed_ready_items.primary_category',
                'feed_ready_items.secondary_category',
                'feed_ready_items.sub_category',
                'feed_ready_items.url',
                'feed_ready_items.lat',
                'feed_ready_items.lng',
                'feed_ready_items.location_label',
                'feed_ready_items.precision_type',
            ]);

        return $rows->map(fn ($row) => $row->toArray())->all();
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
        ?string $category = null,
        ?string $locale = null
    ): array {
        $locale = $locale ?? Loc::current();

        $categoryClause = '';
        $bindings = [
            'lat'    => $lat,
            'lng'    => $lng,
            'radius' => $radiusKm,
            'days'   => $days,
            'locale' => $locale,
        ];

        if ($category !== null && $category !== '' && $category !== 'all') {
            $categoryClause = ' AND LOWER(f.primary_category) = :category';
            $bindings['category'] = mb_strtolower($category);
        }

        $sql = "SELECT * FROM (
            SELECT f.id,
                   COALESCE(t.title, f.title) AS title,
                   COALESCE(t.summary, f.summary) AS summary,
                   f.source, f.published_at,
                   f.primary_category, f.secondary_category, f.sub_category,
                   f.url, f.location_label, f.lat, f.lng,
                   ROUND(
                       (6371.0 * acos(
                           LEAST(1.0,
                               cos(radians(:lat)) * cos(radians(f.lat)) *
                               cos(radians(f.lng) - radians(:lng)) +
                               sin(radians(:lat)) * sin(radians(f.lat))
                           )
                       ))::numeric, 2
                   ) AS distance_km
            FROM feed_ready_items f
            LEFT JOIN news_translations t
                   ON t.news_item_id = f.news_item_id
                  AND t.locale = :locale
            WHERE f.is_active = true
              AND f.is_article = true
              AND f.lat IS NOT NULL
              AND f.lng IS NOT NULL
              AND f.relevance_mode != 'category_only'
              AND f.published_at >= NOW() - make_interval(days => :days)
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
