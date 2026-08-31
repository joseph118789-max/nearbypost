<?php

namespace App\Services;

use App\Models\FeedReadyItem;
use App\Support\Loc;
use App\Support\Taxonomy;
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
            'origin', 'image_path',
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
        ?string $locale = null,
        ?string $sub = null,
        ?string $source = null
    ): array {
        $locale = $locale ?? Loc::current();

        $query = FeedReadyItem::query()
            ->leftJoin('news_translations as t', function ($join) use ($locale) {
                $join->on('t.news_item_id', '=', 'feed_ready_items.news_item_id')
                     ->where('t.locale', '=', $locale);
            })
            ->where('feed_ready_items.is_active', true)
            // One row per story. A multi-point story has one row per place, and
            // a feed that does not sort by distance has no reason to prefer any
            // of them - so exactly one carries this flag.
            ->where('feed_ready_items.is_primary_location', true)
            ->where('feed_ready_items.published_at', '>=', now()->subDays($days));

        if ($category !== null && $category !== '' && $category !== 'all') {
            // Categories are stored lower-case; accept any casing from the URL.
            $query->whereRaw('LOWER(feed_ready_items.primary_category) = ?', [mb_strtolower($category)]);
        }

        // A sub-category narrows within its parent: Sports, then Badminton.
        if ($sub !== null && $sub !== '' && $sub !== 'all') {
            $query->whereRaw('LOWER(feed_ready_items.sub_category) = ?', [mb_strtolower($sub)]);
        }

        // Who wrote it. "Official" is anything but a reader, rather than
        // 'scraper' exactly, so a story from a future third origin is still
        // counted as ours rather than quietly vanishing from both filters.
        if ($source === 'official') {
            $query->where('feed_ready_items.origin', '<>', 'user');
        } elseif ($source === 'unofficial') {
            $query->where('feed_ready_items.origin', '=', 'user');
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
                'feed_ready_items.origin',
                'feed_ready_items.image_path',
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
        ?string $locale = null,
        ?string $sub = null,
        ?string $source = null
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

        if ($sub !== null && $sub !== '' && $sub !== 'all') {
            $categoryClause .= ' AND LOWER(f.sub_category) = :sub';
            $bindings['sub'] = mb_strtolower($sub);
        }

        if ($source === 'official') {
            $categoryClause .= " AND f.origin <> 'user'";
        } elseif ($source === 'unofficial') {
            $categoryClause .= " AND f.origin = 'user'";
        }

        // DISTINCT ON keeps one row per story - the nearest, because the inner
        // ORDER BY sorts by distance within each story. A multi-point story is
        // therefore shown to each reader at whichever of its places is closest
        // to them, and shown once.
        //
        // The key is COALESCE(news_item_id, -id) because news_item_id is
        // nullable: on a bare news_item_id every row with a null id would fold
        // into one and most of the feed would vanish.
        $sql = "SELECT * FROM (
            SELECT DISTINCT ON (COALESCE(f.news_item_id, -f.id)) f.id,
                   COALESCE(t.title, f.title) AS title,
                   COALESCE(t.summary, f.summary) AS summary,
                   f.source, f.published_at,
                   f.primary_category, f.secondary_category, f.sub_category,
                   f.url, f.location_label, f.lat, f.lng,
                   f.origin, f.image_path,
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
            ORDER BY COALESCE(f.news_item_id, -f.id), distance_km ASC
        ) AS nearby
        WHERE distance_km <= :radius
        ORDER BY distance_km ASC, published_at DESC
        LIMIT {$limit}";

        return array_map(
            fn ($row) => (array) $row,
            DB::select($sql, $bindings)
        );
    }

    /**
     * The sub-categories of one category that actually carry stories.
     *
     * Offered rather than the whole taxonomy branch: a reader choosing Sports
     * should be shown Football and Badminton because there is something behind
     * them, not all eighteen because the table defines eighteen.
     *
     * @return list<array{name: string, count: int}>
     */
    public function subCategories(
        string $category,
        int $days = 30,
        ?array $coords = null,
        float $radiusKm = 20.0
    ): array {
        // Beside a Near Me feed the count has to mean what that feed will
        // return, so it is taken within the radius rather than nationally.
        if ($coords !== null) {
            return $this->subCategoriesNear($category, $days, $coords, $radiusKm);
        }

        return FeedReadyItem::query()
            ->where('is_active', true)
            ->where('is_primary_location', true)
            ->where('published_at', '>=', now()->subDays($days))
            ->whereRaw('LOWER(primary_category) = ?', [mb_strtolower($category)])
            ->whereNotNull('sub_category')
            ->whereNotIn('sub_category', ['Others', 'General'])
            ->selectRaw('sub_category AS name, count(*) AS n')
            ->groupBy('sub_category')
            ->orderByRaw('count(*) DESC')
            ->limit(14)
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->n])
            ->all();
    }

    /**
     * The same list, counted only over stories the Near Me feed would show.
     *
     * The filters below mirror nearby() exactly. If that query gains a
     * condition, this one needs it too, or the counts drift from the feed
     * standing next to them.
     *
     * @param  array{lat: float, lng: float}  $coords
     * @return list<array{name: string, count: int}>
     */
    private function subCategoriesNear(string $category, int $days, array $coords, float $radiusKm): array
    {
        $sql = "SELECT name, count(*) AS n FROM (
            SELECT f.sub_category AS name,
                   (6371.0 * acos(
                       LEAST(1.0,
                           cos(radians(:lat)) * cos(radians(f.lat)) *
                           cos(radians(f.lng) - radians(:lng)) +
                           sin(radians(:lat)) * sin(radians(f.lat))
                       )
                   )) AS distance_km
            FROM feed_ready_items f
            WHERE f.is_active = true
              AND f.is_article = true
              AND f.lat IS NOT NULL
              AND f.lng IS NOT NULL
              AND f.relevance_mode != 'category_only'
              AND f.published_at >= NOW() - make_interval(days => :days)
              AND LOWER(f.primary_category) = :category
              AND f.sub_category IS NOT NULL
              AND f.sub_category NOT IN ('Others', 'General')
        ) AS s
        WHERE distance_km <= :radius
        GROUP BY name
        ORDER BY count(*) DESC
        LIMIT 14";

        $rows = DB::select($sql, [
            'lat'      => $coords['lat'],
            'lng'      => $coords['lng'],
            'days'     => $days,
            'category' => mb_strtolower($category),
            'radius'   => $radiusKm,
        ]);

        return array_map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->n], $rows);
    }

    /** Distinct primary categories currently present in the feed. */
    public function categories(): array
    {
        // Fold case: the same category has been written both title-cased and
        // lower-cased, which would otherwise list "Crime & Safety" twice.
        $present = FeedReadyItem::where('is_active', true)
            ->where('is_primary_location', true)
            ->whereNotNull('primary_category')
            ->selectRaw('DISTINCT LOWER(primary_category) AS c')
            ->orderBy('c')
            ->pluck('c')
            ->all();

        // Only the twenty-one real categories are browsable. Older stories
        // carry values from before the taxonomy was enforced - 'nation'
        // covers thousands of rows, and a bare 'business' sat in the
        // navigation beside the real 'business & corporate'.
        return array_values(array_filter($present, fn ($c) => Taxonomy::isCanonical($c)));
    }
}
