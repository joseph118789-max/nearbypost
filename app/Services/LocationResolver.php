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

        // Fold aliases onto their canonical name so the cache hits more often -
        // but only when the alias matched the WHOLE place text, which is what
        // turns "PJ" into "Petaling Jaya".
        //
        // Matching on a trailing segment coarsens instead of normalising:
        // "Jalan Ipoh, Kuala Lumpur" matched only on "Kuala Lumpur" and was
        // geocoded to the city centre, four kilometres from the street named in
        // the story. The alias table exists to normalise names we know, not to
        // discard the specific ones we do not.
        $alias      = LocationAlias::resolve($place);
        $normalized = LocationAlias::normalize($place);

        $matchedWholeText = $alias['status'] === 'matched'
            && mb_strtolower((string) ($alias['matched_on'] ?? '')) === mb_strtolower($normalized);

        $canonical = $matchedWholeText ? $alias['canonical'] : $normalized;

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
