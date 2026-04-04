<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, replaceable geocoding service layer.
 * Default provider: OpenStreetMap Nominatim (free, no API key required).
 * Swap provider by replacing this class or using a child class.
 */
class GeocodingService
{
    private string $provider;
    private string $baseUrl;

    public function __construct()
    {
        // Default to Nominatim; can be extended to accept other providers
        $this->provider  = 'nominatim';
        $this->baseUrl   = 'https://nominatim.openstreetmap.org';
    }

    /**
     * Geocode a canonical place name.
     * Returns ['lat' => float, 'lng' => float, 'label' => string, 'confidence' => float|null]
     * Throws GeocodingException on failure.
     */
    public function geocode(string $placeName): array
    {
        return $this->geocodeNominatim($placeName);
    }

    private function geocodeNominatim(string $placeName): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)',
            'Accept'     => 'application/json',
        ])
        ->timeout(10)
        ->get("{$this->baseUrl}/search", [
            'q'        => $placeName . ', Malaysia',
            'format'   => 'json',
            'limit'    => 1,
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
            'place'   => $placeName,
            'lat'     => $lat,
            'lng'     => $lng,
            'label'   => $label,
            'type'    => $top['type'] ?? null,
        ]);

        return [
            'lat'       => $lat,
            'lng'       => $lng,
            'label'     => $label,
            'confidence'=> $confidence,
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
