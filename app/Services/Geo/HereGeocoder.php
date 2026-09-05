<?php

namespace App\Services\Geo;

use App\Services\GeocodingRateLimitedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HERE Geocoding & Search - the second opinion that is not OpenStreetMap.
 *
 * Every other free geocoder this project can reach (Nominatim, Photon,
 * LocationIQ, Geoapify, OpenCage) is OSM underneath, so they all miss the
 * same places. HERE keeps its own database, allows results to be stored,
 * and its free allowance is far above the ~100 lookups a day this site
 * makes. It is a CANDIDATE SOURCE only: whatever it returns goes through
 * the same polygon, containment and name tests as every other answer.
 *
 * Two endpoints, because they answer different questions:
 *  - Discover: named places (a hospital, a fire station, a mall).
 *  - Geocode:  addresses and towns.
 * Off entirely when no key is configured (services.here.key).
 */
class HereGeocoder
{
    private const DISCOVER = 'https://discover.search.hereapi.com/v1/discover';
    private const GEOCODE  = 'https://geocode.search.hereapi.com/v1/geocode';

    /** Roughly the middle of Peninsular + East Malaysia, for the proximity HERE insists on. */
    private const AT_DEFAULT = ['my' => '4.2,109.0', 'sg' => '1.35,103.82', 'bn' => '4.53,114.72'];

    public function isConfigured(): bool
    {
        return trim((string) config('services.here.key')) !== '';
    }

    /**
     * Candidates for a name, best first, already normalised to the shape the
     * cascade reads. Empty when HERE knows nothing, or is not configured.
     *
     * @param  ?string    $iso2  country to search inside (lowercase), null for the world
     * @param  ?float[]   $bbox  [south, north, west, east] to search inside, e.g. a state's box
     * @return list<array{lat:float, lng:float, label:string, display:string, state:?string, country:?string, confidence:?float, type:string, provider:string}>
     */
    public function search(string $query, ?string $iso2 = 'my', ?array $bbox = null, int $limit = 5): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $iso3 = $iso2 === null ? null : $this->iso3($iso2);
        $out  = [];

        foreach ([self::DISCOVER, self::GEOCODE] as $endpoint) {
            $params = ['q' => $query, 'limit' => $limit, 'lang' => 'en', 'apiKey' => (string) config('services.here.key')];

            if ($endpoint === self::DISCOVER) {
                // Discover needs a place to look from. A state's box when the
                // name gives one; otherwise the country's middle.
                if ($bbox !== null) {
                    $params['in'] = sprintf('bbox:%F,%F,%F,%F', $bbox[2], $bbox[0], $bbox[3], $bbox[1]);
                } else {
                    $params['at'] = self::AT_DEFAULT[$iso2 ?? 'my'] ?? self::AT_DEFAULT['my'];
                }

                if ($iso3 !== null && $bbox === null) {
                    $params['in'] = 'countryCode:' . $iso3;
                }
            } elseif ($iso3 !== null) {
                $params['in'] = 'countryCode:' . $iso3;
            }

            try {
                $r = Http::timeout(10)->get($endpoint, $params);
            } catch (\Throwable $x) {
                Log::warning('HERE request failed', ['endpoint' => $endpoint, 'q' => $query, 'error' => $x->getMessage()]);
                continue;
            }

            if ($r->status() === 429) {
                throw new GeocodingRateLimitedException("HERE rate limited for: {$query}");
            }

            if (!$r->successful()) {
                Log::warning('HERE HTTP error', ['endpoint' => $endpoint, 'q' => $query, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                continue;
            }

            foreach ($r->json('items') ?: [] as $it) {
                $pos = $it['position'] ?? null;

                if (!isset($pos['lat'], $pos['lng'])) {
                    continue;
                }

                $addr = $it['address'] ?? [];
                $out[] = [
                    'lat'        => (float) $pos['lat'],
                    'lng'        => (float) $pos['lng'],
                    'label'      => (string) ($it['title'] ?? $query),
                    'display'    => (string) ($addr['label'] ?? $it['title'] ?? ''),
                    'state'      => $addr['state'] ?? null,
                    'country'    => $addr['countryName'] ?? null,
                    'confidence' => isset($it['scoring']['queryScore']) ? round((float) $it['scoring']['queryScore'], 3) : null,
                    'type'       => (string) ($it['resultType'] ?? ''),
                    'provider'   => 'here',
                ];
            }

            if ($out !== []) {
                break;   // Discover answered; Geocode would only repeat the town
            }
        }

        return $out;
    }

    /** HERE takes ISO 3166 alpha-3. The boundary table is keyed by it too. */
    private function iso3(string $iso2): ?string
    {
        $iso2 = strtoupper($iso2);

        if (method_exists(Boundaries\Iso3166::class, 'iso3')) {
            return Boundaries\Iso3166::iso3($iso2);
        }

        // The country table is keyed by alpha-3; find the row whose alpha-2 is ours.
        foreach (\Illuminate\Support\Facades\DB::table('boundaries')->where('level', 0)->pluck('iso3') as $candidate) {
            if (strtoupper((string) Boundaries\Iso3166::iso2($candidate)) === $iso2) {
                return $candidate;
            }
        }

        return ['MY' => 'MYS', 'SG' => 'SGP', 'BN' => 'BRN', 'ID' => 'IDN', 'TH' => 'THA', 'CN' => 'CHN'][$iso2] ?? null;
    }
}
