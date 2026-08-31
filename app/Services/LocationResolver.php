<?php

namespace App\Services;

use App\Models\LocationAlias;
use Illuminate\Support\Facades\Log;

/**
 * Turn a human place name into coordinates.
 *
 * The browser can only supply coordinates when the visitor grants location
 * permission. Everyone else - a denied prompt, a bot, a server-rendered page -
 * has at most a place name ("Shah Alam", from IP lookup or an explicit choice).
 * Without a name-to-coordinate step those visitors can never see a Nearby feed.
 *
 * Resolution order, cheapest first:
 *   1. location_aliases, to fold "PJ" onto "Petaling Jaya"
 *   2. geocode_cache, already populated by the ingestion pipeline
 *   3. the geocoding provider, which also writes back into the cache
 */
class LocationResolver
{
    public function __construct(private ?GeocodingService $geocoder = null)
    {
        $this->geocoder = $geocoder ?: new GeocodingService();
    }

    /**
     * Returns ['lat' => float, 'lng' => float, 'label' => string] or null.
     */
    public function resolve(?string $placeName): ?array
    {
        $place = trim((string) $placeName);

        if ($place === '') {
            return null;
        }

        // Fold aliases onto their canonical name so the cache hits more often.
        $alias     = LocationAlias::resolve($place);
        $canonical = $alias['status'] === 'matched'
            ? $alias['canonical']
            : LocationAlias::normalize($place);

        if ($canonical === '') {
            return null;
        }

        try {
            $result = $this->geocoder->geocode($canonical);
        } catch (GeocodingException $e) {
            Log::info('Location resolve failed', [
                'place'     => $place,
                'canonical' => $canonical,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'lat'   => (float) $result['lat'],
            'lng'   => (float) $result['lng'],
            'label' => $canonical,
        ];
    }
}
