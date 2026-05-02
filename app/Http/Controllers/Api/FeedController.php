<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\FeedReadyItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class FeedController extends Controller
{
    private const CACHE_TTL_SECONDS = 300;

    private function baseFields(): array
    {
        return ['id','title','summary','source','published_at','primary_category','secondary_category','url'];
    }

    // ── Cache key helpers ────────────────────────────────────────────────────
    private function cacheKey(string $type, ?string $slug = null): string
    {
        return match ($type) {
            'default'   => 'feed:home:default',
            'category'  => 'feed:category:' . $slug,
            'categories'=> 'feed:categories:list',
            default     => throw new \InvalidArgumentException("Unknown cache key type: $type"),
        };
    }

    private function cached(string $key, callable $fetch): array
    {
        return Cache::remember($key, self::CACHE_TTL_SECONDS, $fetch);
    }

    // ── D18: Geo bucket helpers ────────────────────────────────────────────
    // Round to 1 decimal place ≈ ~11 km cells
    private function geoBucket(float $lat, float $lng): string
    {
        return round($lat, 1) . ':' . round($lng, 1);
    }

    // ── D19: Ranking helpers ──────────────────────────────────────────────────
    // Build ORDER BY clause for default/category feeds: newest first, then
    // better-formed items (location_and_category beats location_only beats
    // category_only) as a deterministic tie-break when timestamps are equal.
    private function defaultOrderBy(string $table = 'feed_ready_items'): string
    {
        // Primary: newest first. Tie-break: better-formed items win.
        // location_and_category > location_only > category_only
        $case = "CASE {$table}.relevance_mode"
            . " WHEN 'location_and_category' THEN 1"
            . " WHEN 'location_only' THEN 2"
            . " WHEN 'category_only' THEN 3"
            . " ELSE 4 END";
        return "published_at DESC, {$case} ASC, {$table}.id DESC";
    }

    // Build ORDER BY clause for radius/geo feeds: distance asc, then newest.
    private function geoOrderBy(): string
    {
        return 'distance_km ASC, published_at DESC';
    }

    private function geoCacheKey(float $lat, float $lng, float $radius): string
    {
        return 'feed:geo:' . $this->geoBucket($lat, $lng) . ':' . (int) $radius;
    }

    // ── Endpoints ──────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        return $this->default();
    }

    public function default(): JsonResponse
    {
        $items = $this->cached($this->cacheKey('default'), function () {
            return FeedReadyItem::where('is_active', true)
                ->orderByRaw($this->defaultOrderBy())
                ->limit(20)
                ->get($this->baseFields())
                ->toArray();
        });
        return response()->json($items);
    }

    public function byCategory(string $slug): JsonResponse
    {
        $items = $this->cached($this->cacheKey('category', $slug), function () use ($slug) {
            return FeedReadyItem::where('is_active', true)
                ->where('primary_category', $slug)
                ->orderByRaw($this->defaultOrderBy())
                ->limit(20)
                ->get($this->baseFields())
                ->toArray();
        });
        return response()->json($items);
    }

    public function categories(): JsonResponse
    {
        $cats = $this->cached($this->cacheKey('categories'), function () {
            return FeedReadyItem::where('is_active', true)
                ->select('primary_category')
                ->distinct()
                ->whereNotNull('primary_category')
                ->orderBy('primary_category')
                ->pluck('primary_category')
                ->values()
                ->toArray();
        });

        return response()->json($cats);
    }

    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat'    => 'required|numeric|between:-90,90',
            'lng'    => 'required|numeric|between:-180,180',
            'radius' => 'required|numeric|min:1|max:500',
        ]);

        $lat    = (float) $validated['lat'];
        $lng    = (float) $validated['lng'];
        $radius = (float) $validated['radius'];

        $items = $this->cached($this->geoCacheKey($lat, $lng, $radius), function () use ($lat, $lng, $radius) {
            // Strict geo-serving: require is_active, lat/lng present, and geo-eligible precision.
            // category_only items are excluded because they have no geo data.
            $sql = "SELECT * FROM (
                SELECT id, title, summary, source, published_at,
                       primary_category, secondary_category, url, location_label, lat, lng,
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
            ) AS nearby
            WHERE distance_km <= :radius
            ORDER BY distance_km ASC, published_at DESC
            LIMIT 20";

            $rows = DB::select($sql, ['lat' => $lat, 'lng' => $lng, 'radius' => $radius]);

            return array_map(function($row) {
                return [
                    'id'                => (int) $row->id,
                    'title'             => $row->title,
                    'summary'           => $row->summary,
                    'source'            => $row->source,
                    'published_at'       => $row->published_at,
                    'primary_category'   => $row->primary_category,
                    'secondary_category'=> $row->secondary_category,
                    'url'               => $row->url,
                    'location_label'     => $row->location_label,
                    'lat'               => $row->lat !== null ? (float) $row->lat : null,
                    'lng'               => $row->lng !== null ? (float) $row->lng : null,
                    'distance_km'       => (float) $row->distance_km,
                ];
            }, $rows);
        });

        return response()->json($items);
    }

    public function filter(Request $request): JsonResponse
    {
        $hasRadius = $request->filled('radius');
        $hasLat    = $request->filled('lat');
        $hasLng    = $request->filled('lng');

        if ($hasRadius && (!$hasLat || !$hasLng)) {
            throw ValidationException::withMessages([
                'geo' => ['When radius is provided, lat and lng are required.'],
            ]);
        }

        $rules = [];
        if ($request->has('primary_category')) {
            $rules['primary_category'] = 'string|max:100';
        }
        if ($request->has('secondary_category')) {
            $rules['secondary_category'] = 'string|max:100';
        }
        if ($request->has('time')) {
            $rules['time'] = 'integer|min:1';
        }
        if ($hasRadius) {
            $rules['radius'] = 'numeric|min:1|max:500';
            $rules['lat']    = 'required|numeric|between:-90,90';
            $rules['lng']    = 'required|numeric|between:-180,180';
        }

        $validated = validator($request->all(), $rules)->validate();

        if ($hasRadius) {
            $lat    = (float) $validated['lat'];
            $lng    = (float) $validated['lng'];
            $radius = (float) $validated['radius'];

            // Radius filter: strict geo path, require valid precision + coordinates.
            $where = [
                'is_active = true',
                'is_article = true',
                'lat IS NOT NULL',
                'lng IS NOT NULL',
                "relevance_mode != 'category_only'",
            ];
            $bindings = [
                'lat'    => $lat,
                'lng'    => $lng,
                'radius' => $radius,
            ];

            if (!empty($validated['primary_category'])) {
                $where[] = 'primary_category = :primary_category';
                $bindings['primary_category'] = $validated['primary_category'];
            }
            if (!empty($validated['secondary_category'])) {
                $where[] = 'secondary_category = :secondary_category';
                $bindings['secondary_category'] = $validated['secondary_category'];
            }
            if (!empty($validated['time'])) {
                $where[] = 'published_at >= NOW() - INTERVAL :time HOUR';
                $bindings['time'] = (int) $validated['time'];
            }

            $haversine = "ROUND(
                (6371.0 * acos(
                    LEAST(1.0,
                        cos(radians(:lat)) * cos(radians(lat)) *
                        cos(radians(lng) - radians(:lng)) +
                        sin(radians(:lat)) * sin(radians(lat))
                    )
                ))::numeric, 2
            )";

            $sql = "SELECT * FROM (
                SELECT id, title, summary, source, published_at,
                       primary_category, secondary_category, url,
                       location_label, lat, lng,
                       ({$haversine}) AS distance_km
                FROM feed_ready_items
                WHERE " . implode(' AND ', $where) . "
            ) AS filtered
            WHERE distance_km <= :radius
            ORDER BY distance_km ASC, published_at DESC
            LIMIT 20";

            $rows = DB::select($sql, $bindings);

            $items = array_map(fn($row) => [
                'id'                => (int) $row->id,
                'title'             => $row->title,
                'summary'           => $row->summary,
                'source'            => $row->source,
                'published_at'      => $row->published_at,
                'primary_category'  => $row->primary_category,
                'secondary_category'=> $row->secondary_category,
                'url'               => $row->url,
                'location_label'    => $row->location_label,
                'lat'               => $row->lat !== null ? (float) $row->lat : null,
                'lng'               => $row->lng !== null ? (float) $row->lng : null,
                'distance_km'       => (float) $row->distance_km,
            ], $rows);

            return response()->json($items);
        }

        $query = FeedReadyItem::where('is_active', true);
        if (!empty($validated['primary_category'])) {
            $query->where('primary_category', $validated['primary_category']);
        }
        if (!empty($validated['secondary_category'])) {
            $query->where('secondary_category', $validated['secondary_category']);
        }
        if (!empty($validated['time'])) {
            $query->where('published_at', '>=', now()->subHours((int) $validated['time']));
        }

        return response()->json(
            $query->orderByRaw($this->defaultOrderBy())->limit(20)->get($this->baseFields())
        );
    }
}
