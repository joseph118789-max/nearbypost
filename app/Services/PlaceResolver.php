<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\Geo\CountryCode;
use App\Services\Geo\PlaceScale;
use Illuminate\Support\Facades\Log;

/**
 * The places OpenStreetMap does not know.
 *
 * The AI was taught to name the most specific place a story mentions, and it
 * does: a bridge, a square, a municipal stadium. The cost of that precision is
 * that the more exact the answer, the less likely a general gazetteer holds it.
 * Stadium Majlis Perbandaran Manjung is a real stadium hosting real matches and
 * OpenStreetMap has never heard of it - within 15km of Seri Manjung it records
 * three named venues, and that is not one of them.
 *
 * So when the map cannot answer, ask somewhere else before giving up:
 *
 *   2. WIKIDATA. Free, no key, and strong exactly where OSM is weak - stadiums,
 *      monuments, airports, campuses. It had the Manjung stadium under its
 *      Malay name. Of twelve places tried it knew eight.
 *
 *   3. THE PARENT LANDMARK. "Royal Polo Grounds, Istana Pasir Pelangi, Johor
 *      Baru" finds nothing, but the palace it stands in is on every map. Drop
 *      whole components from the front - never words from a name.
 *
 * What this deliberately does NOT do is ask a language model for coordinates.
 * Measured against four places whose position is known, DeepSeek was out by
 * 0.6km, 2.4km, 2.9km and 3.2km - and its three attempts at Dataran Putrajaya
 * agreed within 70 metres while being 2.4km wrong. It is most confident when it
 * is inventing, so there is no test that separates its good answers from its
 * bad ones. A Wikidata coordinate was typed by a person and can be corrected by
 * another person; a guessed one has never been seen by anybody.
 *
 * Everything here is guarded, because a loosened search fails in the worst
 * possible way - it succeeds. "Stadium Manjung" returns Stadium TLDM Lumut, a
 * different stadium 6km away. "Pengkalan Abai" returns a PETRONAS station in
 * the right district. Both look like clean hits.
 */
class PlaceResolver
{
    private const UA = 'Nearbypost/1.0 (+https://nearbypost.com; agentnearby@gmail.com)';

    /** Malaysia, generously drawn. A rescue that lands outside it is not a rescue. */
    private const MY_BOUNDS = ['lat' => [0.5, 7.6], 'lng' => [99.0, 119.5]];

    /**
     * Things that are never the place a news story is about, however well the
     * name matches. The PETRONAS station stood in for a village because a
     * village query with the village removed is just a district query, and a
     * district is full of petrol stations.
     */
    private const NOT_A_LANDMARK = [
        'fuel', 'restaurant', 'cafe', 'fast_food', 'atm', 'bank', 'pharmacy',
        'parking', 'bench', 'toilets', 'bus_stop', 'shop', 'supermarket',
        'convenience', 'clinic', 'car_wash', 'hairdresser',
    ];

    /**
     * Administrative areas. Reaching one of these means the cascade has walked
     * all the way up to "somewhere in this district", which is the vagueness
     * the specificity rules exist to prevent. Better to admit defeat and let a
     * person look at it than to place a story in the middle of a district and
     * call that an answer.
     */
    private const ADMINISTRATIVE = [
        'administrative', 'state', 'county', 'district', 'province', 'region',
        'municipality', 'political',
    ];

    /**
     * A settlement small enough to BE the place. "Rumah Panjang Raba Tiput,
     * Layar, Sarawak" names a longhouse no map records; the owner's reading
     * was "the location has to be at Layar" - and Layar is a town of a few
     * streets. A reader near Layar is who the story is for. A city is not
     * this: pinning an unmapped Kuching venue at Kuching's centre would put
     * it 10 km from most of Kuching.
     */
    private const SETTLEMENT = ['city', 'town', 'village',   /* a small town the map labels city (Mukah) stands in; the population rule refuses the real cities */ 'hamlet', 'suburb', 'neighbourhood', 'quarter', 'locality', 'isolated_dwelling'];

    /** Every door tried, in order, with what came back. */
    private array $attempts = [];

    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * Try the remaining doors after the map has said no.
     *
     * Returns the same shape as GeocodingService::geocode(), or null when the
     * place is genuinely unidentifiable and belongs in front of a person.
     */
    public function resolve(string $placeName): ?array
    {
        $this->attempts = [];

        return $this->viaWikidata($placeName)
            ?? $this->viaParentLandmark($placeName);
    }

    // -- Step 2: Wikidata --------------------------------------------------

    /**
     * Ask Wikidata, on the full string and then on the venue name alone.
     *
     * "Victoria Bridge, Enggor, Kuala Kangsar" is a label this pipeline
     * composed; nobody registered it. The name people actually gave the thing
     * is the part before the first comma.
     */
    private function viaWikidata(string $placeName): ?array
    {
        foreach ($this->candidates($placeName) as $query) {
            try {
                $search = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)
                    ->get('https://www.wikidata.org/w/api.php', [
                        'action'   => 'wbsearchentities',
                        'search'   => $query,
                        'language' => 'en',
                        'uselang'  => 'en',
                        'format'   => 'json',
                        'limit'    => 5,
                    ]);

                if (!$search->successful()) {
                    $this->note('wikidata', $query, 'http ' . $search->status());
                    continue;
                }

                $hits = $search->json('search') ?? [];

                if ($hits === []) {
                    $this->note('wikidata', $query, 'no entity of that name');
                    continue;
                }

                foreach ($hits as $hit) {
                    $result = $this->wikidataEntity($hit, $query);

                    if ($result) {
                        return $result;
                    }
                }

                $this->note('wikidata', $query, count($hits) . ' entities, none usable');

            } catch (\Throwable $e) {
                $this->note('wikidata', $query, 'error: ' . $e->getMessage());
            }
        }

        return null;
    }

    /** Read one entity and accept it only if it is a Malaysian physical place. */
    private function wikidataEntity(array $hit, string $query): ?array
    {
        $id = $hit['id'] ?? null;

        if (!$id) {
            return null;
        }

        $entity = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)
            ->get("https://www.wikidata.org/wiki/Special:EntityData/{$id}.json");

        if (!$entity->successful()) {
            return null;
        }

        $coords = $entity->json("entities.{$id}.claims.P625.0.mainsnak.datavalue.value");

        // No coordinate means it is a person, a company or an idea - something
        // that shares a name with a place but is not one.
        if (!isset($coords['latitude'], $coords['longitude'])) {
            return null;
        }

        $lat = (float) $coords['latitude'];
        $lng = (float) $coords['longitude'];

        // The name guard. This step exists to rescue Malaysian venues, and a
        // same-named bridge in Australia would geocode perfectly and put a
        // Perak story on the wrong continent.
        if (!$this->inMalaysia($lat, $lng)) {
            $this->note('wikidata', $query, "{$id} is outside Malaysia");

            return null;
        }

        $label = $entity->json("entities.{$id}.labels.ms.value")
            ?? $entity->json("entities.{$id}.labels.en.value")
            ?? ($hit['label'] ?? $query);

        Log::info('Place rescued by Wikidata', [
            'query' => $query, 'entity' => $id, 'lat' => $lat, 'lng' => $lng,
        ]);

        $this->note('wikidata', $query, "found {$id}");

        return [
            'lat'        => $lat,
            'lng'        => $lng,
            'label'      => $label,
            // A hand-entered coordinate for a named venue is a precise point.
            // It is not a guess about how famous the place is, which is what
            // the map's own score measures.
            'confidence' => 0.85,
            'provider'   => 'wikidata',
        ];
    }

    // -- Step 3: the parent landmark ---------------------------------------

    /**
     * Drop the venue and look for the landmark it stands in.
     *
     * Components only, never words. Truncating a name is how "Stadium Majlis
     * Perbandaran Manjung" becomes "Stadium Manjung" and lands on the wrong
     * stadium; dropping "Royal Polo Grounds" from the front leaves "Istana
     * Pasir Pelangi", which is a different, real, findable thing.
     */
    private function viaParentLandmark(string $placeName): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $placeName))));

        // Needs something to drop and something to keep.
        if (count($parts) < 2) {
            return null;
        }

        for ($drop = 1; $drop < count($parts); $drop++) {
            $remaining = array_slice($parts, $drop);
            $query     = implode(', ', $remaining);

            // Stop before the country. Walking up from "Volkspark Stadion,
            // Hamburg, Germany" eventually asks Nominatim for "Germany" - and
            // from a Malaysia-biased index the answer is the German EMBASSY IN
            // KUALA LUMPUR. Four continents' worth of venues came back pinned
            // within 300 metres of each other on Jalan Kia Peng that way, and a
            // hospital wall in Kathmandu appeared 8km from a reader in Desa
            // ParkCity.
            //
            // A country is not a parent landmark. It is the whole country, and
            // the answer to "where in it" is not a building.
            if (PlaceScale::isBareCountry($query)) {
                $this->note('parent', $query, 'rejected: a country is not a landmark');

                return null;
            }

            // The parts as written, then the parent with the state alone, then
            // the parent by itself: "Rantau Panjang, Sektor Pengkalan Kubor,
            // Kelantan" is nothing to the map, "Rantau Panjang, Kelantan" is
            // the town (owner: "if small town, just use the parent; if still
            // don't have, use the higher parent").
            $asks = [$query];

            if (count($remaining) > 2) {
                $asks[] = $remaining[0] . ', ' . $remaining[count($remaining) - 1];
            }

            if (count($remaining) > 1) {
                $asks[] = $remaining[0];
            }

            $top = null;

            foreach (array_values(array_unique($asks)) as $ask) {
                try {
                    $top = $this->queryNominatimRaw($ask);
                } catch (\Throwable $e) {
                    $this->note('parent', $ask, 'error: ' . $e->getMessage());
                    continue;
                }

                if ($top) {
                    $query = $ask;
                    break;
                }

                $this->note('parent', $ask, 'no result');
            }

            if (!$top) {
                continue;
            }

            $type  = strtolower((string) ($top['type'] ?? ''));
            $class = strtolower((string) ($top['class'] ?? ''));

            // The petrol-station guard.
            if (in_array($type, self::NOT_A_LANDMARK, true)) {
                $this->note('parent', $query, "rejected: matched a {$type}");
                continue;
            }

            // The embassy guard, belt to the braces above.
            //
            // Searching for a country returns its embassy here; searching for a
            // city sometimes does too. An embassy is a real building with real
            // coordinates, so nothing downstream can tell it is the wrong
            // answer - unless the question mentioned one.
            $label = mb_strtolower((string) ($top['display_name'] ?? ''));
            $asked = mb_strtolower($placeName);

            $diplomatic = ['embassy', 'kedutaan', 'consulate', 'konsulat', 'high commission', 'suruhanjaya tinggi'];

            foreach ($diplomatic as $word) {
                if (str_contains($label, $word) && !str_contains($asked, $word)) {
                    $this->note('parent', $query, "rejected: matched an embassy nobody asked about");

                    return null;
                }
            }

            // The vagueness guard. Walking up to the district means we have
            // stopped answering the question that was asked.
            if (in_array($type, self::ADMINISTRATIVE, true) || $class === 'boundary') {
                // Presint 5, Putrajaya is an administrative boundary 1.7 by 3 km:
                // a neighbourhood a reader can name, so it stands for a place
                // inside it like a village does. A district-sized box does not.
                $bb = $top['boundingbox'] ?? null;
                $small = is_array($bb) && count($bb) === 4
                    && ((float) $bb[1] - (float) $bb[0]) < 0.06 && ((float) $bb[3] - (float) $bb[2]) < 0.06;

                if (!$small) {
                    $this->note('parent', $query, "rejected: {$type} is an area, not a place");

                    return null;
                }

                $this->note('parent', $query, sprintf('%s is a small area (%.1f x %.1f km): accepted as a neighbourhood', $type,
                    ((float) $bb[1] - (float) $bb[0]) * 111.0, ((float) $bb[3] - (float) $bb[2]) * 111.0));
                $settlement = true;
            }

            Log::info('Place rescued by parent landmark', [
                'original' => $placeName, 'matched' => $query, 'type' => $type,
            ]);

            $this->note('parent', $query, "found a {$type}");

            $settlement = (isset($settlement) && $settlement) || ($class === 'place' && in_array($type, self::SETTLEMENT, true));

            // Size, not the map's label, decides whether a settlement can stand
            // for a place inside it. Bintangor is a town a reader can walk
            // across; Kajang is labelled a town too and has 300,000 people.
            // Nominatim's box for a place NODE is a fixed +-0.04 degrees (12.6
            // km for every town, large or small), so the box says nothing;
            // the population the gazetteer holds for the same place does.
            if ($settlement) {
                $people = \Illuminate\Support\Facades\DB::table('gazetteer')->where('source', 'geonames')->whereNotNull('population')
                    ->where('name_key', \App\Services\Geo\GazetteerSearch::key(explode(',', (string) ($top['display_name'] ?? $query))[0]))
                    ->whereBetween('lat', [(float) $top['lat'] - 0.05, (float) $top['lat'] + 0.05])
                    ->whereBetween('lng', [(float) $top['lon'] - 0.05, (float) $top['lon'] + 0.05])
                    ->max('population');

                if ($people !== null && (int) $people > 150000) {   // GeoNames holds the DISTRICT's count for a district town: Kuala Krai 105,900, Maran 111,056 - both small towns (owner). Kajang, at 300,000+, still refused
                    $this->note('parent', $query, sprintf('rejected: %s has %s people - too large to stand for a place inside it',
                        explode(',', (string) ($top['display_name'] ?? $query))[0], number_format((int) $people)));

                    return null;
                }
            }

            return [
                'lat'        => (float) $top['lat'],
                'lng'        => (float) $top['lon'],
                'label'      => explode(',', (string) ($top['display_name'] ?? $query))[0],
                'display'    => (string) ($top['display_name'] ?? $query),
                'state'      => $top['address']['state'] ?? null,
                'country'    => $top['address']['country'] ?? null,
                // Deliberately below a direct hit: this is the building the
                // story's venue sits in, not the venue itself - or, lower
                // still, the settlement it is in.
                'confidence' => $settlement ? 0.55 : 0.7,
                'provider'   => $settlement ? 'nominatim_settlement' : 'nominatim_parent',
                'precision'  => $settlement ? 'approximate_area' : null,
                'note'       => $settlement
                    ? sprintf('Placed at %s, the %s the story names; "%s" itself is on no map.', explode(',', (string) ($top['display_name'] ?? $query))[0], $type, explode(',', $placeName)[0])
                    : null,
            ];
        }

        return null;
    }

    // -- shared ------------------------------------------------------------

    /** The full label, then the name people actually use. */
    private function candidates(string $placeName): array
    {
        $first = trim(explode(',', $placeName)[0]);
        $list  = [$placeName, $first];

        // "waters off Penang Port": the sea has no pin, the port has
        if (preg_match('/^(?:the\s+)?(?:waters?\s+off|off\s+the\s+coast\s+of|waters\s+of|perairan|offshore\s+(?:of|from))\s+(.+)$/iu', $first, $m)) {
            array_unshift($list, trim($m[1]));
        }

        // "Spark Bar in Inanam", "pasar malam di Kepong": the place after the
        // preposition is the parent, and it is worth asking for on its own.
        if (preg_match('/^(.+?)\s+(?:in|at|di|near|berhampiran)\s+(.+)$/iu', $first, $m)) {
            $list[] = trim($m[2]);
        }

        return array_values(array_unique(array_filter($list, fn ($q) => mb_strlen($q) >= 4)));
    }

    private function inMalaysia(float $lat, float $lng): bool
    {
        return $lat >= self::MY_BOUNDS['lat'][0] && $lat <= self::MY_BOUNDS['lat'][1]
            && $lng >= self::MY_BOUNDS['lng'][0] && $lng <= self::MY_BOUNDS['lng'][1];
    }

    /** One Nominatim lookup, returning the raw top hit so guards can read its type. */
    private function queryNominatimRaw(string $query): ?array
    {
        // Search the country the NAME gives, not always Malaysia. Pinned to
        // "my", a Hamburg stadium could never be found - and the walk-up then
        // asked for "Germany", whose only match inside Malaysia is the German
        // Embassy in Kuala Lumpur. The constraint was not just failing; it was
        // manufacturing a confident wrong answer.
        $country = CountryCode::forPlace($query);

        // The public instance allows one request a second and this runs behind
        // a stage that already failed, so it is never in a hurry.
        $params = ['q' => $query, 'format' => 'json', 'limit' => 5, 'addressdetails' => 1, 'countrycodes' => $country];
        $route  = \App\Services\Geo\NominatimRouter::baseFor($country);   // the container for that country, or the public instance
        $base   = $route['url'];
        $local  = $route['local'];
        $response = null;

        if ($local) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/json'])->timeout(15)->get($base . '/search', $params);
            } catch (\Throwable $x) {
                $response = null;   // our instance is down; the public one below
            }
        }

        if ($response === null || !$response->successful()) {
            usleep(1200000);
            $response = Http::withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/json'])
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/search', $params);
        }

        if (!$response->successful()) {
            return null;
        }

        $results = $response->json();

        if (empty($results)) {
            return null;
        }

        // "Kuala Krai" is a district AND a town; our engine lists the district
        // first and the walk-up refused it as an area, never seeing the town
        // one line below. The owner's rule: a small town stands for what is
        // in it. So: a settlement of that name first, then any place that is
        // not an area, then whatever came first.
        foreach ($results as $h) {
            if (($h['class'] ?? '') === 'place' && in_array((string) ($h['type'] ?? ''), self::SETTLEMENT, true)) {
                return $h;
            }
        }

        // "Mukah, Sarawak": five boundaries (division, district, sub-district) and the town
        // itself not among them. Ask the map for the settlement by name before settling for
        // an area.
        try {
            $params['featureType'] = 'settlement';
            $again = Http::withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/json'])->timeout(15)->get($base . '/search', $params);

            foreach ($again->successful() ? ($again->json() ?: []) : [] as $h) {
                if (($h['class'] ?? '') === 'place' && in_array((string) ($h['type'] ?? ''), self::SETTLEMENT, true)) {
                    return $h;
                }
            }
        } catch (\Throwable $x) {
            // the boundaries below will do
        }

        foreach ($results as $h) {
            if (($h['class'] ?? '') !== 'boundary' && !in_array((string) ($h['type'] ?? ''), self::ADMINISTRATIVE, true)) {
                return $h;
            }
        }

        return $results[0];
    }

    private function note(string $step, string $query, string $outcome): void
    {
        $this->attempts[] = ['step' => $step, 'query' => $query, 'outcome' => $outcome];
    }
}
