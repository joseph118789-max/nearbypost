<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, replaceable geocoding service layer.
 * Default provider: OpenStreetMap Nominatim (free, no API key required).
 * Swap provider by replacing this class or using a child class.
 *
 * Results are cached in `geocode_cache` keyed on the normalised place text
 * (Manual V6 §26.6). ~420 distinct places serve ~5,700 articles, so the cache
 * turns a multi-hour backfill into a few minutes and keeps us inside
 * Nominatim's 1 request/second usage policy.
 */
class GeocodingService
{
    private string $provider;
    private string $baseUrl;

    /** Nominatim usage policy: at most 1 request per second. */
    private const MIN_INTERVAL_MICROSECONDS = 1_100_000;

    /** Wall-clock time of the last live provider call, for throttling. */
    private static ?float $lastLiveCallAt = null;

    public function __construct()
    {
        $this->provider = 'nominatim';
        $this->baseUrl  = 'https://nominatim.openstreetmap.org';
    }

    /**
     * Geocode a place name, using the cache when possible.
     * Returns ['lat' => float, 'lng' => float, 'label' => string, 'confidence' => float|null, 'cached' => bool]
     * Throws GeocodingException on failure.
     */
    public function geocode(string $placeName): array
    {
        $key = $this->cacheKey($placeName);

        if ($key === '') {
            throw new GeocodingException('Empty place name');
        }

        $cached = DB::table('geocode_cache')->where('place_key', $key)->first();

        if ($cached) {
            DB::table('geocode_cache')->where('id', $cached->id)->update([
                'hits'       => $cached->hits + 1,
                'updated_at' => now(),
            ]);

            if ($cached->status !== 'success') {
                throw new GeocodingException("Cached miss for: {$placeName}");
            }

            return [
                'lat'        => (float) $cached->lat,
                'lng'        => (float) $cached->lng,
                'label'      => $cached->label ?? $placeName,
                'confidence' => $cached->confidence !== null ? (float) $cached->confidence : null,
                'cached'     => true,
            ];
        }

        try {
            $result = $this->geocodeNominatim($placeName);
        } catch (GeocodingRateLimitedException $e) {
            // Never cache a rate limit — it says nothing about the place.
            throw $e;
        } catch (GeocodingException $e) {
            $this->remember($key, $placeName, null, 'not_found');
            throw $e;
        }

        $this->remember($key, $placeName, $result, 'success');
        $result['cached'] = false;

        return $result;
    }

    /** Normalised cache key: lowercased, collapsed whitespace, no trailing country. */
    private function cacheKey(string $placeName): string
    {
        $text = trim($placeName);
        $text = preg_replace('/\s*,\s*Malaysia\s*$/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_strtolower(trim($text));
    }

    private function remember(string $key, string $placeText, ?array $result, string $status): void
    {
        DB::table('geocode_cache')->updateOrInsert(
            ['place_key' => $key],
            [
                'place_text' => mb_substr($placeText, 0, 250),
                'lat'        => $result['lat'] ?? null,
                'lng'        => $result['lng'] ?? null,
                'label'      => isset($result['label']) ? mb_substr($result['label'], 0, 250) : null,
                'confidence' => $result['confidence'] ?? null,
                'provider'   => $this->provider,
                'status'     => $status,
                'hits'       => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** Space live provider calls at least MIN_INTERVAL_MICROSECONDS apart. */
    private function throttle(): void
    {
        if (self::$lastLiveCallAt !== null) {
            $elapsed = (microtime(true) - self::$lastLiveCallAt) * 1_000_000;
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                usleep((int) (self::MIN_INTERVAL_MICROSECONDS - $elapsed));
            }
        }

        self::$lastLiveCallAt = microtime(true);
    }

    private function geocodeNominatim(string $placeName): array
    {
        $this->throttle();

        $response = Http::withHeaders([
            'User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)',
            'Accept'     => 'application/json',
        ])
        ->timeout(10)
        ->get("{$this->baseUrl}/search", [
            'q'              => $placeName . ', Malaysia',
            'format'         => 'json',
            'limit'          => 1,
            'addressdetails' => 1,
        ]);

        if ($response->status() === 429) {
            throw new GeocodingRateLimitedException("Nominatim rate limited for: {$placeName}");
        }

        if (!$response->successful()) {
            throw new GeocodingException("Nominatim HTTP {$response->status()} for: {$placeName}");
        }

        $results = $response->json();
        if (empty($results)) {
            throw new GeocodingException("No results from Nominatim for: {$placeName}");
        }

        $top = $results[0];
        $lat = (float) $top['lat'];
        $lng = (float) $top['lon'];

        // Nominatim doesn't give a normalised confidence; derive from importance if available
        $confidence = isset($top['importance']) ? round((float) $top['importance'], 4) : null;

        // Label from display_name (first part before comma)
        $label = $top['display_name'] ?? $placeName;
        $label = explode(',', $label)[0];

        Log::info('Geocoding nominatim success', [
            'place' => $placeName,
            'lat'   => $lat,
            'lng'   => $lng,
            'label' => $label,
            'type'  => $top['type'] ?? null,
        ]);

        return [
            'lat'        => $lat,
            'lng'        => $lng,
            'label'      => $label,
            'confidence' => $confidence,
        ];
    }

    public function getProvider(): string
    {
        return $this->provider;
    }
}

// ── Exceptions ────────────────────────────────────────────────────────────────

class GeocodingException extends \Exception {}
class GeocodingRateLimitedException extends GeocodingException {}
