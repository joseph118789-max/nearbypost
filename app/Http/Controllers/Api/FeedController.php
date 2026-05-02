<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\FeedReadyItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeedController extends Controller
{
    private function baseFields(): array
    {
        return ['id','title','summary','source','published_at','primary_category','secondary_category','url'];
    }

    public function index(): JsonResponse
    {
        return $this->default();
    }

    public function default(): JsonResponse
    {
        $items = FeedReadyItem::where('is_active', true)
            ->where('relevance_mode', '!=', 'category_only')
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get($this->baseFields());

        return response()->json($items);
    }

    public function byCategory(string $slug): JsonResponse
    {
        $items = FeedReadyItem::where('is_active', true)
            ->where('relevance_mode', '!=', 'category_only')
            ->where('primary_category', $slug)
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get($this->baseFields());

        return response()->json($items);
    }

    public function categories(): JsonResponse
    {
        $cats = FeedReadyItem::where('is_active', true)
            ->select('primary_category')
            ->distinct()
            ->whereNotNull('primary_category')
            ->orderBy('primary_category')
            ->pluck('primary_category');

        return response()->json($cats->values());
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
        ORDER BY distance_km
        LIMIT 20";

        $rows = DB::select($sql, ['lat' => $lat, 'lng' => $lng, 'radius' => $radius]);

        $items = array_map(function($row) {
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
                       location_label, ({$haversine}) AS distance_km
                FROM feed_ready_items
                WHERE " . implode(' AND ', $where) . "
            ) AS filtered
            WHERE distance_km <= :radius
            ORDER BY distance_km
            LIMIT 20";

            $rows = DB::select($sql, $bindings);

            $items = array_map(fn($row) => (object)[
                'id'                => $row->id,
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
            $query->orderBy('published_at', 'desc')->limit(20)->get($this->baseFields())
        );
    }
}
