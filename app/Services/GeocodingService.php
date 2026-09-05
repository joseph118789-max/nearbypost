<?php

namespace App\Services;

use App\Services\Geo\HereGeocoder;
use App\Services\Geo\GazetteerSearch;
use App\Services\Geo\NameVariants;
use App\Services\Geo\RoadGeocoder;
use App\Services\Geo\PlaceScale;

use App\Services\Geo\CountryCode;
use App\Services\Geo\PlaceContainment;
use App\Services\Geo\Boundaries\BoundaryCheck;
use App\Services\Geo\NameMatch;
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

    /**
     * Which door actually opened for the most recent lookup, and every door
     * tried on the way. A place rescued from Wikidata is not a Nominatim
     * result and should not be recorded as one - and when nothing works at
     * all, the list of what was tried is what a person reviewing the place
     * needs so they do not repeat it by hand.
     */
    private ?string $lastProvider = null;
    private array $lastAttempts = [];

    /** What was tried on the most recent lookup, in order. */
    public function lastAttempts(): array
    {
        return $this->lastAttempts;
    }

    /** Which provider answered the most recent lookup. */
    public function lastProvider(): string
    {
        return $this->lastProvider ?? $this->provider;
    }

    private const PUBLIC_URL = 'https://nominatim.openstreetmap.org';

    /** True when the configured Nominatim is our own: no rate limit, no throttle. */
    private bool $local = false;

    public function __construct()
    {
        $this->provider = 'nominatim';
        $this->baseUrl  = (string) config('services.nominatim.url', self::PUBLIC_URL);
        $this->local    = str_contains($this->baseUrl, '127.0.0.1') || str_contains($this->baseUrl, 'localhost');
    }

    /**
     * One Nominatim call. Our own instance first; if it is down (the import
     * still running, the container stopped) the public one answers instead,
     * at the public one's pace. A miss on our instance is NOT a reason to ask
     * the public one - same data, same answer.
     */
    private function nominatimGet(string $path, array $params, int $timeout = 10): \Illuminate\Http\Client\Response
    {
        $headers = ['User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)', 'Accept' => 'application/json'];

        // the container that holds the country asked about; the public
        // instance for a country none of ours holds
        $route = \App\Services\Geo\NominatimRouter::baseFor($params['countrycodes'] ?? null);

        if ($route['local']) {
            try {
                $r = Http::withHeaders($headers)->timeout($timeout)->get($route['url'] . $path, $params);

                if ($r->successful()) {
                    return $r;
                }

                Log::warning('Local Nominatim answered ' . $r->status() . '; asking the public instance', ['path' => $path]);
            } catch (\Throwable $x) {
                Log::warning('Local Nominatim unreachable; asking the public instance', ['error' => mb_substr($x->getMessage(), 0, 120)]);
            }

            $this->throttle(true);

            return Http::withHeaders($headers)->timeout($timeout)->get(self::PUBLIC_URL . $path, $params);
        }

        $this->throttle();

        return Http::withHeaders($headers)->timeout($timeout)->get(self::PUBLIC_URL . $path, $params);
    }

    /**
     * Geocode a place name, using the cache when possible.
     * Returns ['lat' => float, 'lng' => float, 'label' => string, 'confidence' => float|null, 'cached' => bool]
     * Throws GeocodingException on failure.
     */
    /** The publisher's country for the current call, ISO2 lowercase, or null. */
    private ?string $home = 'my';

    /**
     * @param ?string $home the publisher's country (ISO2), the prior for any
     *                      name that does not say where it is; null for an
     *                      international outlet. Defaults to the site's own.
     */
    public function geocode(string $placeName, ?string $home = 'my'): array
    {
        $this->home = $home === null ? null : strtolower($home);
        $key = $this->cacheKey($placeName);

        if ($key === '') {
            throw new GeocodingException('Empty place name');
        }

        // Cleared before the cache is consulted, not after. A cached miss
        // throws from inside the block below - and when it did, the previous
        // place's attempts were still in this property, so the review queue
        // showed a Mersing river mouth with Kota Tinggi's search log under it.
        $this->lastAttempts = [];
        $this->lastSuggestions = [];
        $this->lastProvider = $this->provider;

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
                // Rows written before these columns existed return null, and a
                // name that cannot be completed is simply left as it was.
                'state'      => $cached->state ?? null,
                'country'    => $cached->country ?? null,
                'cached'     => true,
            ];
        }

        $this->lastProvider = $this->provider;
        $this->lastAttempts = [];

        // A kilometre marker or a road junction is not in any index; it is
        // computed from the road geometry. Tried first, because a gazetteer
        // asked about "KM 328 PLUS, Tapah" answers with Tapah.
        if (RoadGeocoder::looksLikeRoadPoint($placeName)) {
            $roads = new RoadGeocoder();
            $road  = null;

            try {
                $road = $roads->resolve($placeName, $this->home);
            } catch (\Throwable $x) {
                $this->note('road', $placeName, 'road data unavailable: ' . mb_substr($x->getMessage(), 0, 80));
            }

            $this->lastAttempts = array_merge($this->lastAttempts, $roads->attempts());

            if ($road !== null) {
                $this->lastProvider = $road['provider'];
                $this->remember($key, $placeName, $road, 'success');
                $road['cached'] = false;

                return $road;
            }
        }

        // "antara Stesen Sri Raya dan Stesen Bandar Tun Hussein Onn": a
        // point BETWEEN two places is the midpoint of the two, when both are
        // found and stand within reach of each other.
        // "antara A dan B", "between A and B", and "Kepong-Rawang route" (two
        // towns joined by a dash, then route/road/highway): a stretch between
        // two places
        $venue = trim((string) explode(',', $placeName)[0]);
        $m = [];
        $isBetween = !$this->inBetween && (
            preg_match('/^\s*(?:antara|between|di antara)\s+(.+?)\s+(?:dan|and|&)\s+(.+?)\s*$/iu', $venue, $m)
            || preg_match('/^\s*(?:laluan\s+|jalan\s+|lebuhraya\s+)?([\p{L}\'\.\s]{3,40}?)\s*[-\x{2013}\x{2014}]\s*([\p{L}\'\.\s]{3,40}?)\s+(?:route|road|highway|expressway|stretch|laluan|jalan|lebuhraya|trunk\s+road)\s*$/iu', $venue, $m)
        );

        if ($isBetween) {
            $tail = str_contains($placeName, ',') ? substr($placeName, strpos($placeName, ',')) : '';
            $this->inBetween = true;
            $ends = [];
            $log  = [];

            try {
                foreach ([$m[1], $m[2]] as $end) {
                    // The end on its own first: "Rawang" is the town; "Rawang,
                    // Kuala Lumpur" (the story's address tail) found a shop of
                    // that name in the city. The tail only when the bare name
                    // gives nothing. Each recursive call starts its own attempt
                    // log; keep them all.
                    $found = null;

                    foreach (array_values(array_unique([trim($end), trim($end) . $tail])) as $ask) {
                        try {
                            $found = $this->geocode($ask, $this->home);
                            $log = array_merge($log, $this->lastAttempts);
                            break;
                        } catch (\Throwable $x) {
                            $log = array_merge($log, $this->lastAttempts, [['step' => 'between', 'query' => $ask, 'outcome' => 'not found: ' . mb_substr($x->getMessage(), 0, 60)]]);
                        }
                    }

                    if ($found !== null) {
                        $ends[] = $found;
                    }
                }

                // one end found, the other not: ask for the other in the found end's state
                if (count($ends) === 1 && $tail === '' && !empty($ends[0]['state'])) {
                    $missing = trim(mb_strtolower($ends[0]['label'] ?? '') === mb_strtolower(trim($m[1])) ? $m[2] : $m[1]);
                    $other   = trim($m[1]) === $missing ? $m[2] : $m[1];

                    // which end did we get? the one whose words the found label carries
                    $gotFirst = \App\Services\Geo\NameMatch::holds(trim($m[1]), (string) ($ends[0]['display'] ?? $ends[0]['label'] ?? ''));
                    $missing  = trim($gotFirst ? $m[2] : $m[1]);

                    try {
                        $found = $this->geocode($missing . ', ' . $ends[0]['state'], $this->home);
                        $log = array_merge($log, $this->lastAttempts);
                        $ends = $gotFirst ? [$ends[0], $found] : [$found, $ends[0]];
                    } catch (\Throwable $x) {
                        $log = array_merge($log, $this->lastAttempts, [['step' => 'between', 'query' => $missing . ', ' . $ends[0]['state'], 'outcome' => 'not found: ' . mb_substr($x->getMessage(), 0, 60)]]);
                    }
                }
            } finally {
                $this->inBetween = false;
                $this->lastAttempts = $log;
            }

            if (count($ends) === 2) {
                $gap = $this->km((float) $ends[0]['lat'], (float) $ends[0]['lng'], (float) $ends[1]['lat'], (float) $ends[1]['lng']);

                if ($gap <= 40.0) {   // two MRT stations are a few km apart; two towns on one road up to a few dozen
                    $mid = [
                        'lat' => ((float) $ends[0]['lat'] + (float) $ends[1]['lat']) / 2,
                        'lng' => ((float) $ends[0]['lng'] + (float) $ends[1]['lng']) / 2,
                        'label' => 'between ' . ($ends[0]['label'] ?? trim($m[1])) . ' and ' . ($ends[1]['label'] ?? trim($m[2])),
                        'display' => 'between ' . ($ends[0]['label'] ?? trim($m[1])) . ' and ' . ($ends[1]['label'] ?? trim($m[2])) . $tail,
                        'confidence' => 0.6, 'provider' => 'midpoint', 'precision' => 'approximate_point',
                        'state' => $ends[0]['state'] ?? null, 'country' => $ends[0]['country'] ?? null,
                        'note' => sprintf('Placed midway between %s and %s, %.1f km apart', $ends[0]['label'] ?? trim($m[1]), $ends[1]['label'] ?? trim($m[2]), $gap),
                    ];
                    $this->note('between', $placeName, sprintf('midpoint of the two ends, %.1f km apart', $gap));
                    $this->lastProvider = 'midpoint';
                    $this->remember($key, $placeName, $mid, 'success');
                    $mid['cached'] = false;

                    return $mid;
                }

                $this->note('between', $placeName, sprintf('the two ends are %.0f km apart - not a stretch between them', $gap));
            }
        }

        try {
            $result = $this->geocodeNominatim($placeName);
        } catch (GeocodingRateLimitedException $e) {
            // Nominatim is not the point. Coordinates are.
            //
            // The site reads lat/lng out of the database to work out distance;
            // it does not care which gazetteer supplied them. When the public
            // Nominatim instance is exhausted - which a day of auditing is
            // enough to do - asking a different OSM index is better than
            // leaving a story with no location for hours.
            $photon = $this->geocodePhoton($placeName);

            if ($photon !== null) {
                $this->lastProvider = 'photon';
                $this->remember($key, $placeName, $photon, 'success');
                $photon['cached'] = false;

                return $photon;
            }

            // Never cache a rate limit - it says nothing about the place.
            throw $e;
        } catch (GeocodingException $e) {
            $this->lastAttempts = [['step' => 'nominatim', 'query' => $placeName, 'outcome' => $e->getMessage()]];

            // Our own gazetteer - every downloaded place list - before any
            // live service. Local, unlimited, and where the rural names are.
            $gaz = $this->tryGazetteer($placeName);

            if ($gaz !== null) {
                $this->lastProvider = $gaz['provider'];
                $this->remember($key, $placeName, $gaz, 'success');
                $gaz['cached'] = false;

                return $gaz;
            }

            // A database that is not OpenStreetMap, asked the same question
            // and held to the same tests. Only when a key is configured.
            $here = $this->tryHere($placeName);

            if ($here !== null) {
                $this->lastProvider = 'here';
                $this->remember($key, $placeName, $here, 'success');
                $here['cached'] = false;

                return $here;
            }

            // The name written the other ways it is written - the acronym
            // gone, the other language, the other spelling, the distinctive
            // words alone - against our own map engine, which has no limit.
            // Each answer is held to the polygon, the name and the district.
            $variant = $this->tryVariants($placeName);

            if ($variant !== null) {
                $this->remember($key, $placeName, $variant, 'success');
                $variant['cached'] = false;

                return $variant;
            }

            // Three cheaper questions before giving up on the map, measured on
            // the 116 names a person had been asked about:
            //
            //  - The name without its bracketed acronym. "Borneo Convention
            //    Centre Kuching (BCCK), Kuching, Sarawak" is not in any index;
            //    the same string minus "(BCCK)" is, on the first try. Ten of
            //    the 116 vanished this way, all ten correctly - same name,
            //    same town, nothing loosened.
            //  - The bare place, town dropped. Loosened, so held to the town.
            //  - Photon's fuzzy index. Loosened further, so held the same way.
            //
            // Without the containment rule the last two answered 57 times and
            // were wrong 45 of them. With it, 11 right and 45 refused.
            $loosened = $this->tryLoosened($placeName);

            if ($loosened !== null) {
                $this->remember($key, $placeName, $loosened, 'success');
                $loosened['cached'] = false;

                return $loosened;
            }

            // The map has never heard of it. That is common for exactly the
            // answers we want most - a bridge, a square, a municipal stadium -
            // so ask the sources that hold what a general gazetteer does not
            // before deciding the place does not exist.
            $resolver = new PlaceResolver();
            $rescued  = $resolver->resolve($placeName);

            $this->lastAttempts = array_merge($this->lastAttempts, $resolver->attempts());

            // The walk-up is where the embassy came from. Same test.
            if ($rescued !== null && !$this->within($placeName, $rescued, (string) ($rescued['provider'] ?? 'resolver'))) {
                $rescued = null;
            }

            if ($rescued === null) {
                // LAST RUNG: THE TOWN THE NAME ALREADY GAVE, PLUS THE STATE.
                //
                // Owner's formula, 4 Sep 2026: "should try Tamu Kedayan, Miri,
                // Sarawak. If cant find and exhausted all combination. Then use
                // Miri, Sarawak."
                //
                // Everything above has been tried and refused, so the choice
                // here is a town-level pin or no pin at all. The walk-up in
                // PlaceResolver deliberately refuses a town this big ("too
                // large to stand for a place inside it") and it is right to,
                // while a narrower answer is still possible. Once nothing
                // narrower is possible, refusing costs the story its place on
                // the map for the sake of a precision nobody can have.
                //
                // So this runs last and only last, it is marked approximate so
                // the reader's radius treats it as a town and not a doorstep,
                // and the state on the end is what keeps it in the right half
                // of the country.
                $rescued = $this->townRung($placeName);
            }

            if ($rescued === null) {
                $this->remember($key, $placeName, null, 'not_found');
                throw $e;
            }

            $this->lastProvider = $rescued['provider'];
            $this->remember($key, $placeName, $rescued, 'success');
            $rescued['cached'] = false;

            return $rescued;
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
                // Stored so a cache hit can still complete a bare name. Without
                // these the completion would apply only to fresh lookups, which
                // is almost nothing once the cache is warm.
                'state'      => isset($result['state']) ? mb_substr((string) $result['state'], 0, 120) : null,
                'country'    => isset($result['country']) ? mb_substr((string) $result['country'], 0, 120) : null,
                'provider'   => $result['provider'] ?? $this->provider,
                'status'     => $status,
                'hits'       => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** Space live provider calls at least MIN_INTERVAL_MICROSECONDS apart. */
    private function throttle(bool $force = false): void
    {
        if ($this->local && !$force) {
            return;   // our own server; the only limit is the CPU
        }

        if (self::$lastLiveCallAt !== null) {
            $elapsed = (microtime(true) - self::$lastLiveCallAt) * 1_000_000;
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                usleep((int) (self::MIN_INTERVAL_MICROSECONDS - $elapsed));
            }
        }

        self::$lastLiveCallAt = microtime(true);
    }

    /**
     * Resolve a place, preferring Malaysia but not requiring it.
     *
     * Malaysia is tried first because most place text here is Malaysian and
     * much of it is ambiguous worldwide - Sepang, Kuantan and Victoria all
     * exist in other countries. When that finds nothing the name is resolved
     * globally, so a story from Henan or Jakarta is placed where it actually
     * happened instead of failing or landing on a Malaysian near-match.
     *
     * Distance then does the rest: a Henan story is four thousand kilometres
     * from a Kuala Lumpur reader and simply falls outside their radius.
     */
    private function geocodeNominatim(string $placeName): array
    {
        // The publisher's country first, for any name that does not say where
        // it is. "Kota Baru" from a Malaysian paper is Kota Bharu, not the
        // Kotabaru in Indonesia that a world search returns first; "Jurong
        // East" from the Straits Times is in Singapore. Only when the home
        // search finds nothing is the name tried as written, anywhere - so a
        // story from Henan is still in Henan.
        $namesItsCountry = CountryCode::forPlace($placeName, null) !== null;

        // THE STATE GOES ON THE END OF EVERY QUERY.
        //
        // Owner's rule, 4 Sep 2026: "should try Tamu Kedayan, Miri, Sarawak.
        // If cant find and exhausted all combination. Then use Miri, Sarawak.
        // This is the formula."
        //
        // ⛔ The deeper ladder below already obeyed this, but THIS query - the
        // first one, and the one that answers most of the time - sent the name
        // exactly as the model wrote it. Measured 4 Sep: 91 of 892 placed
        // stories (10%) were searched with no state on them at all, because the
        // model had written a bare name - "Kuah jetty", "Bukit Lagi". They
        // landed correctly, but by luck, not by method, and luck drifts.
        //
        // So: the state first, the bare name only if that finds nothing.
        $stated = $this->withState($placeName, $placeName);

        if (!$namesItsCountry && $this->home !== null) {
            foreach (array_values(array_unique([$stated, $placeName])) as $query) {
                try {
                    return $this->named($placeName, $this->claimed($placeName, $this->queryNominatim($query, $this->home), 'nominatim'));
                } catch (GeocodingRateLimitedException $e) {
                    throw $e;
                } catch (GeocodingException $e) {
                    // Not at home, or not disambiguated by it. Try the world.
                }
            }
        }

        return $this->named($placeName, $this->claimed($placeName, $this->queryNominatim($placeName, $namesItsCountry ? null : ''), 'nominatim'));
    }

    /**
     * The answer must carry the name it was asked for. A geocoder that
     * answers a partial match - the town's tokens without the place's - is
     * answering a different question, and inside the right state that is
     * the hardest wrong answer to see.
     */
    private function named(string $claim, array $result): array
    {
        $bare    = trim((string) (explode(',', preg_replace('/\s*\([^)]*\)/', '', $claim))[0] ?? $claim));
        $display = (string) ($result['display'] ?? ($result['label'] . ', ' . ($result['state'] ?? '')));

        // the answer's OWN name, not its address: a hair salon in Bandar Tun
        // Hussein Onn is not Stesen Bandar Tun Hussein Onn; and its kind must
        // fit (a station for a station)
        $own = explode(',', $display)[0];

        if (!NameMatch::holds($bare, $own) || !NameMatch::kindMatches($bare, $own . ' ' . (string) ($result['osm_type'] ?? ''))) {
            $this->note('nominatim', $claim, 'answer is a different place: ' . mb_substr($display, 0, 70));

            throw new GeocodingException("Answer for {$claim} is a different place: " . mb_substr($display, 0, 60));
        }

        return $result;
    }

    /**
     * The structural test: is the answer inside the country and state the
     * name claims? Polygons, not spellings.
     *
     * A geocoder's wrong answers are confident and well-formed - a Moto3 win
     * at Brno came back as the Czech embassy in Kuala Lumpur, with real
     * coordinates and a real address. Nothing about the ANSWER says it is
     * wrong. Only the question does: the name said Czech Republic, and the
     * point is not in it. So every candidate, from every source, passes
     * through here before anything downstream sees it.
     *
     * 'unknown' - a country the table has no polygon for - is not a refusal.
     * The caller's weaker, text-based rule still applies to loosened steps.
     */
    private function within(string $claim, array $result, string $step): bool
    {
        $v = (new BoundaryCheck())->verify($claim, (float) $result['lat'], (float) $result['lng'], $this->home);

        if ($v['verdict'] === 'outside') {
            $this->note($step, $claim, 'refused by boundary: ' . $v['detail']);

            return false;
        }

        $this->note('boundary', $claim, $v['detail']);

        return true;
    }

    /** within(), for the paths that signal a miss by throwing. */
    private function claimed(string $claim, array $result, string $step): array
    {
        if (!$this->within($claim, $result, $step)) {
            throw new GeocodingException("Answer for {$claim} is outside the place it names");
        }

        return $result;
    }


    /**
     * The loosened searches. See the call site for why each exists and why
     * two of the three are held to the town the story named.
     */
    private function tryLoosened(string $placeName): ?array
    {
        $stripped = trim(preg_replace('/\s*\([^)]*\)/', '', $placeName));
        $stripped = preg_replace('/\s*,\s*,/', ',', $stripped);

        // Same name, brackets gone. Not loosened: nothing dropped but an
        // acronym, so no containment test is needed.
        if ($stripped !== '' && $stripped !== $placeName) {
            try {
                $r = $this->geocodeNominatim($stripped);
                $this->note('nominatim', $stripped, 'found (acronym stripped)');
                $r['label'] = $r['label'] ?: explode(',', $stripped)[0];

                return $r;
            } catch (GeocodingRateLimitedException $e) {
                throw $e;
            } catch (GeocodingException $e) {
                $this->note('nominatim', $stripped, $e->getMessage());
            }
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $stripped))));
        $bare  = $parts[0] ?? '';

        // Nothing after the place itself means nothing to hold an answer to.
        // A loosened search with no anchor is the trap, not the shortcut.
        if (count($parts) < 2 || mb_strlen($bare) < 4) {
            return null;
        }

        // A highway marker is a point on a line, and the line runs the length
        // of the state. Held only to the state, "KM 328.3 PLUS, Bidor-Tapah,
        // Perak" was accepted at the same road near Ipoh, 60 km away - inside
        // Perak, and wrong. No loosened search can place a chainage; it needs
        // the road's geometry or a person.
        if (preg_match('/\bkm\s*\d|\bkilomet/iu', $stripped)) {
            $this->note('nominatim', $bare, 'refused: a highway marker cannot be placed by a loosened search');

            return null;
        }

        // Never the bare name. "Dataran Bandaraya" alone came back from Kota
        // Bharu, then Johor Bahru, for a square in Kota Kinabalu; every index
        // returns its most famous bearer of a name, and the polygon then only
        // gets to refuse it. The state the name claims goes on every loosened
        // query, so the index is asked for the right one from the start.
        $bare = $this->withState($bare, $stripped);

        // The place with its state, in Malaysia, held to the town.
        try {
            $r = $this->claimed($stripped, $this->queryNominatim($bare, $this->home ?? ''), 'nominatim');
            $display = $r['display'] ?? ($r['label'] . ', ' . ($r['state'] ?? ''));

            // Two tests, the same two the bounded search applies: the answer
            // is in the town the name gave AND it carries the name. Without
            // the second, "Dataran Bandaraya, Kota Kinabalu" was accepted at
            // Dewan Bandaraya - the city hall, which happens to stand beside
            // the square, but the test did not know that.
            if (PlaceContainment::holds($stripped, $display, $r['state'] ?? null) && NameMatch::holds($parts[0], $display)) {
                $this->note('nominatim', $bare, 'found, inside ' . implode(', ', array_slice($parts, 1)));

                return $r;
            }

            $this->note('nominatim', $bare, 'rejected: ' . mb_substr($display, 0, 60)
                . (PlaceContainment::holds($stripped, $display, $r['state'] ?? null) ? ' is a different name' : ' is not in ' . implode(', ', array_slice($parts, 1))));
        } catch (GeocodingRateLimitedException $e) {
            throw $e;
        } catch (GeocodingException $e) {
            $this->note('nominatim', $bare, $e->getMessage());
        }

        // Photon, held the same way.
        $p = $this->geocodePhoton($bare);

        if ($p !== null && $this->within($stripped, $p, 'photon') && PlaceContainment::holds($stripped, $p['display'] ?? '', $p['state'] ?? null) && NameMatch::holds($parts[0], $p['display'] ?? '')) {
            $this->note('photon', $bare, 'found, inside ' . implode(', ', array_slice($parts, 1)));
            $this->lastProvider = 'photon';
            $p['provider'] = 'photon';

            return $p;
        }

        $this->note('photon', $bare, $p === null ? 'no result' : 'rejected: ' . mb_substr($p['display'] ?? '', 0, 60) . ' is not in ' . implode(', ', array_slice($parts, 1)));

        // Last: search INSIDE the state the name claims.
        return $this->boundedSearch($stripped, $parts, $bare);
    }

    /**
     * The gazetteer, held to the same three tests: inside the state's
     * polygon, carrying the name, within reach of the district the name
     * gives. A name that gives NO state or district is accepted only on an
     * exact, unique match inside the country - a bare "Sungai Siput" is not
     * one place.
     */
    private function tryGazetteer(string $placeName): ?array
    {
        $stripped = trim(preg_replace('/\s*\([^)]*\)/', '', $placeName));
        $parts    = array_values(array_filter(array_map('trim', explode(',', $stripped))));
        $bare     = $parts[0] ?? $stripped;

        if (mb_strlen($bare) < 3 || PlaceScale::isTooBigToBeNear($bare)) {
            return null;
        }

        $check = new BoundaryCheck();
        $e     = $check->expectation($stripped, $this->home);
        $iso3  = $e['country'] ?? null;
        $state = $e['state'] ?? null;
        $bbox  = null;

        if ($iso3 !== null && $state !== null) {
            $box = DB::table('boundaries')->where('iso3', $iso3)->where('level', 1)->where('code', $state)->first(['min_lat', 'max_lat', 'min_lng', 'max_lng']);

            if ($box) {
                $bbox = [(float) $box->min_lat, (float) $box->max_lat, (float) $box->min_lng, (float) $box->max_lng];
            }
        }

        try {
            $candidates = (new GazetteerSearch())->find($bare, $iso3, $state, $bbox);
        } catch (\Throwable $x) {
            $this->note('gazetteer', $bare, 'search failed: ' . mb_substr($x->getMessage(), 0, 80));

            return null;
        }

        if ($candidates === []) {
            $this->note('gazetteer', $bare, 'nothing' . ($state ? " inside {$e['state_name']}" : ''));

            return null;
        }

        $anchor = count($parts) > 1 ? $this->districtAnchor($parts, $e) : null;   // a town anchors even when no state was claimed
        $kept   = [];

        // "Taman Mawar, Machap Baru, Alor Gajah, Melaka" names a district. If
        // that district could not be placed, the state alone is not a hold:
        // the Taman Mawar in Klebang Besar is in Melaka too, 25 km away.
        $givesDistrict = false;

        foreach (array_slice($parts, 1) as $part) {
            if (mb_strtolower($part) !== mb_strtolower((string) ($e['state_name'] ?? '')) && !PlaceScale::isTooBigToBeNear($part)) {
                $givesDistrict = true;
            }
        }

        if ($givesDistrict && $anchor === null) {
            $this->note('gazetteer', $bare, 'the district the name gives could not be anchored; not accepting on the state alone');

            return null;
        }

        foreach ($candidates as $c) {
            if (!$this->within($stripped, $c, 'gazetteer')) {
                continue;
            }

            if (!NameMatch::holds($bare, $c['label']) || !NameMatch::kindMatches($bare, $c['label'])) {   // the row's own name and kind, not its address
                continue;
            }

            if ($anchor !== null && !$this->nearAnchor((float) $c['lat'], (float) $c['lng'], $anchor, (string) ($c['display'] ?? ''))) {
                $this->note('gazetteer', $bare, sprintf('%s is %.0f km from %s - not the one', mb_substr($c['display'], 0, 50), $this->km($c['lat'], $c['lng'], $anchor['lat'], $anchor['lng']), $anchor['name']));
                continue;
            }

            $kept[] = $c;
        }

        if ($kept === []) {
            $this->note('gazetteer', $bare, count($candidates) . ' candidate(s), none passing the name/state/district tests');

            return null;
        }

        // No state given by the name: only an exact, unique name in the country.
        if ($state === null) {
            $exact = array_values(array_filter($kept, fn ($c) => GazetteerSearch::key($c['label']) === GazetteerSearch::key($bare)));
            $distinct = array_unique(array_map(fn ($c) => sprintf('%.2f,%.2f', $c['lat'], $c['lng']), $exact));

            if ($exact === [] || count($distinct) > 1) {
                $this->note('gazetteer', $bare, 'no state in the name and ' . ($exact === [] ? 'no exact match' : count($distinct) . ' places carry that exact name'));

                return null;
            }

            $kept = $exact;
        }

        $best = $kept[0];
        $this->note($best['provider'], $bare, 'found: ' . mb_substr($best['display'], 0, 70) . ($anchor ? sprintf(', %.0f km from %s', $this->km($best['lat'], $best['lng'], $anchor['lat'], $anchor['lng']), $anchor['name']) : ''));

        return ['lat' => $best['lat'], 'lng' => $best['lng'], 'label' => $best['label'], 'display' => $best['display'],
            'confidence' => $best['confidence'], 'state' => $best['state'], 'country' => $best['country'], 'provider' => $best['provider']];
    }

    /**
     * HERE, full name, inside the state the name claims. Accepted on the
     * same three tests as everything else: polygon, name, containment.
     */
    private function tryHere(string $placeName): ?array
    {
        $api = new HereGeocoder();

        if (!$api->isConfigured()) {
            return null;
        }

        $stripped = trim(preg_replace('/\s*\([^)]*\)/', '', $placeName));
        $parts    = array_values(array_filter(array_map('trim', explode(',', $stripped))));
        $bare     = $parts[0] ?? $stripped;
        $iso2     = CountryCode::forPlace($placeName, $this->home) ?? $this->home;
        $e        = (new BoundaryCheck())->expectation($stripped, $this->home);
        $bbox     = null;

        if (($e['state'] ?? null) !== null && ($e['country'] ?? null) !== null) {
            $box = DB::table('boundaries')->where('iso3', $e['country'])->where('level', 1)->where('code', $e['state'])
                ->first(['min_lat', 'max_lat', 'min_lng', 'max_lng']);

            if ($box) {
                $bbox = [(float) $box->min_lat, (float) $box->max_lat, (float) $box->min_lng, (float) $box->max_lng];
            }
        }

        try {
            $candidates = $api->search($stripped, $iso2 === '' ? null : $iso2, $bbox);
        } catch (GeocodingRateLimitedException $x) {
            $this->note('here', $stripped, 'rate limited');

            return null;
        }

        if ($candidates === []) {
            $this->note('here', $stripped, 'nothing');

            return null;
        }

        foreach ($candidates as $c) {
            $display = $c['display'] ?: $c['label'];

            if (!$this->within($stripped, $c, 'here')) {
                continue;
            }

            // the answer's OWN name and kind, not its address ("Steven Hair
            // Saloon, ..., Bandar Tun Hussein Onn" was taken for the station)
            if (!NameMatch::holds($bare, (string) ($r['label'] ?? explode(',', $display)[0])) || !NameMatch::kindMatches($bare, (string) ($r['label'] ?? explode(',', $display)[0]))) {
                $this->note('here', $stripped, 'a different name: ' . mb_substr($display, 0, 60));
                continue;
            }

            if (count($parts) > 1 && !PlaceContainment::holds($stripped, $display, $c['state'] ?? null)) {
                $this->note('here', $stripped, 'not in ' . implode(', ', array_slice($parts, 1)) . ': ' . mb_substr($display, 0, 60));
                continue;
            }

            $this->note('here', $stripped, 'found: ' . mb_substr($display, 0, 70));
            $c['confidence'] = min(0.8, (float) ($c['confidence'] ?? 0.7));

            return $this->anchorToOsm($c, $bare);
        }

        return null;
    }

    /**
     * The same place in our own map data, if it is there: a search for the
     * name inside a ~400 m box around HERE's point. OSM's coordinates are
     * ours to keep; HERE's, on the Base plan, are not. When OSM has nothing
     * of that name there, HERE's point stands, marked 'here' so the cache
     * can let it go after 30 days (see purgeTemporary()).
     */
    private function anchorToOsm(array $c, string $bare): array
    {
        $d = 0.002;   // about 220 m of latitude; the box is roughly 400 m across

        try {
            $r = $this->nominatimGet('/search', ['q' => $bare, 'format' => 'json', 'limit' => 3, 'addressdetails' => 1, 'bounded' => 1,
                'viewbox' => implode(',', [$c['lng'] - $d, $c['lat'] - $d, $c['lng'] + $d, $c['lat'] + $d])], 8);

            foreach ($r->successful() ? ($r->json() ?: []) : [] as $h) {
                $display = (string) ($h['display_name'] ?? '');

                if (NameMatch::holds($bare, $display)) {
                    $this->note('here', $bare, 'same place in OSM ' . sprintf('%.0f m away', $this->km($c['lat'], $c['lng'], (float) $h['lat'], (float) $h['lon']) * 1000) . ': ' . mb_substr($display, 0, 60));

                    return ['lat' => (float) $h['lat'], 'lng' => (float) $h['lon'], 'label' => explode(',', $display)[0], 'display' => $display,
                        'state' => $h['address']['state'] ?? $c['state'], 'country' => $h['address']['country'] ?? $c['country'],
                        'confidence' => 0.8, 'provider' => 'here+osm'];
                }
            }
        } catch (\Throwable $x) {
            // no OSM confirmation available; HERE's point stands
        }

        $this->note('here', $bare, 'not in OSM within 400 m; HERE point kept (temporary cache)');

        return $c;
    }

    /** Cache rows that may not be kept for good. Run daily. */
    public static function purgeTemporary(): int
    {
        return DB::table('geocode_cache')->where('provider', 'here')->where('updated_at', '<', now()->subDays(30))->delete();
    }

    /**
     * Every other spelling of the place, against our own Nominatim and the
     * gazetteer, inside the state the name claims and within reach of the
     * district it gives. "Pusat Konvensyen Borneo Kuching (BCCK)" is found as
     * "Borneo Convention Centre Kuching"; "Mahkamah Sesyen Kuala Lumpur" as
     * the sessions court in the KL court complex. A sessions court in
     * Georgetown for a Sungai Petani name is refused by the district test -
     * the probe that motivated this found two such before the test was added.
     */
    private function tryVariants(string $placeName): ?array
    {
        $stripped = trim(preg_replace('/\s*\([^)]*\)/', '', $placeName));
        // "Jambatan Sungai Johor di Telok Sengat": the town after di/at/in/near
        // is a part of the address, so it can anchor the answer
        $parts    = array_values(array_filter(array_map('trim', preg_split('/,|\s+(?:di|at|in|near)\s+/iu', $stripped))));
        $bare     = trim((string) (preg_split('/,|\s+(?:di|at|in|near)\s+/iu', $placeName)[0] ?? $placeName));   // the venue before the town

        // "waters off Penang Port": the sea has no pin, the port has
        $bare = trim(preg_replace('/^(?:the\s+)?(?:waters?\s+off|off\s+the\s+coast\s+of|waters\s+of|perairan|offshore\s+(?:of|from))\s+/iu', '', $bare));

        // The name as written comes first: the direct step asked for it with
        // its whole address and got nothing, but "Sungai Perak, Kuala Kangsar"
        // (the venue and the town) is what the owner typed and it answered.
        $variants = array_values(array_unique(array_merge([trim(preg_replace('/\s*\([^)]*\)/', '', $bare))], NameVariants::of($bare))));

        // a KM marker is a point on a road, never a name to respell: "KM110
        // of the East Coast Expressway" matched the East Coast Mall
        if (preg_match('/\b(?:km|kilomet(?:er|re)s?)\s*\.?\s*\d/iu', $placeName)) {
            $this->note('variant', $bare, 'a KM marker: no other spelling to try');

            return null;
        }

        if ($variants === []) {
            return null;
        }

        $check  = new BoundaryCheck();
        $e      = $check->expectation($stripped, $this->home);
        $state  = $e['state_name'] ?? null;

        // Singapore is a country, a state and a town at once: "Sephora Ion
        // Orchard, Singapore" has a state to be bounded by and a town to be
        // anchored to, both called Singapore
        if ($state === null && in_array($e['country'] ?? '', ['SGP'], true)) {
            $state = 'Singapore';
        }

        $anchor = count($parts) > 1 ? $this->districtAnchor($parts, $e) : null;   // a town anchors even when no state was claimed
        $givesDistrict = false;

        foreach (array_slice($parts, 1) as $part) {
            if (mb_strtolower($part) !== mb_strtolower((string) $state) && !PlaceScale::isTooBigToBeNear($part)) {
                $givesDistrict = true;
            }
        }

        // What the ORIGINAL name is made of, so that a one-word variant cannot
        // accept just any place carrying that word: the answer must still
        // carry at least half of the name's distinctive words.
        // Two shared words is enough: an official name is long ("State
        // Legislative Assembly (DUN) Complex") and the map's is short ("Sarawak
        // State Assembly"); asking for half of five refused the right answer.
        $original = NameMatch::distinctiveWords($bare);
        $needed   = max(1, min(2, (int) ceil(count($original) / 2)));

        $why   = '';
        $holds = function (array $c, string $variant) use ($stripped, $anchor, $givesDistrict, $check, $original, $needed, $bare, $state, $placeName, &$why): bool {
            $why = '';   // every variable the closure reads is listed: a missing one is a warning Laravel turns into an exception the catch below swallows
            if ($check->verify($stripped, (float) $c['lat'], (float) $c['lng'], $this->home)['verdict'] === 'outside') {
                { $why = 'outside the polygon'; return false; }
            }

            if (!NameMatch::holds($variant, explode(',', (string) $c['display'])[0])) {   // the answer's OWN name, not its address: "borneo kuching" matched a tour company in Kuching
                { $why = 'name test'; return false; }
            }

            // The shared-words guard is for the ONE-WORD variants, which could
            // otherwise match any place carrying that word. A variant with two
            // or more distinctive words is already held to all of them by the
            // name test above.
            if ($original !== [] && count(NameMatch::distinctiveWords($variant)) <= 1) {   // nothing distinctive in the original ("Jabatan Muzium Malaysia"): the name test above is the whole hold
                $shared = NameMatch::countPresent($original, (string) $c['display']);   // twins count: "Negara" for "Malaysia"

                if ($shared < $needed) {
                    { $why = 'shared words'; return false; }
                }
            }

            // a hall is not a mosque, whatever the variant left out - and a
            // school named after the same sultan is not the hall either: the
            // answer's own name must carry the kind the question named
            // the map's type stands beside the name for the kind test: "Sri Raya"
            // (railway station) is a stesen
            $kindText = explode(',', (string) $c['display'])[0] . ' ' . (string) ($c['osm_type'] ?? '');

            if (NameMatch::kindsConflict($bare, $kindText) || !NameMatch::kindMatches($bare, $kindText)) {
                { $why = 'kind'; return false; }
            }

            if ($givesDistrict && $anchor === null) {
                { $why = 'district named but not anchored'; return false; }   // a district was named and could not be anchored: the state alone is no hold
            }

            // nothing to anchor to and no state claimed: only the WHOLE name
            // may match - a pair matched "Sungai Telok Sengar" in Terengganu
            // for a bridge at Telok Sengat, Johor
            if ($anchor === null && $state === null && !NameMatch::holds($bare, explode(',', (string) $c['display'])[0])) {
                { $why = 'only the whole name may match here'; return false; }
            }

            // When only PART of the name matched (a reduced variant), the
            // answer's own name may carry nothing the question never spoke
            // of: "Ducati Borneo Kuching" is not the convention centre. When
            // the WHOLE name holds, in either language, extra official words
            // are fine: "... Associations Sarawak Malaysia Cultural Centre
            // (FORUM)" is the FORUM building.
            $ownName = explode(',', (string) $c['display'])[0];

            // the map's own alias in brackets - "... Cultural Centre (FORUM)" -
            // equal to a word the question used means the whole name holds
            $alias = preg_match('/\(([^)]+)\)/u', $ownName, $m) ? mb_strtolower(trim($m[1])) : null;
            $asked = array_map('mb_strtolower', NameMatch::distinctiveWords($placeName));
            // "whole" means every word of the question is in the answer's own
            // name, generic ones included - "Kedai Runcit Ah Chong" is not whole
            // in "Ah Chong 亚春面之家" (a noodle house), so its strangers count
            $whole = NameMatch::holdsAll($bare, $ownName) || ($alias !== null && in_array($alias, $asked, true));

            if (!$whole && NameMatch::strangers($placeName . ' ' . (string) $state, $ownName) !== []) {
                { $why = 'stranger words'; return false; }
            }

            if (!$this->nearAnchor((float) $c['lat'], (float) $c['lng'], $anchor, (string) ($c['display'] ?? ''))) {
                { $why = 'not near the anchor'; return false; }
            }

            return true;
        };

        // the town the name gives (its first part after the place), for the query
        $town = null;

        foreach (array_slice($parts, 1) as $part) {
            if (mb_strtolower($part) !== mb_strtolower((string) $state) && !PlaceScale::isTooBigToBeNear($part) && !preg_match('/\b(road|jalan|highway|lebuhraya)\b/iu', $part)) {
                $town = $part;
                break;
            }
        }

        // the state's box, for the bare form of a spelling
        $box = ($e['state'] ?? null) !== null && ($e['country'] ?? null) !== null
            ? DB::table('boundaries')->where('iso3', $e['country'])->where('level', 1)->where('code', $e['state'])->first(['min_lat', 'max_lat', 'min_lng', 'max_lng'])
            : null;

        foreach ($variants as $variant) {
            $low   = mb_strtolower($variant);
            $withTown  = $town !== null && !str_contains($low, mb_strtolower($town));
            $withState = $state !== null && !$this->alreadyNamesState($low, $e);

            // Every address form the owner would type: with the town and the
            // state, with the town alone, and the spelling on its own held
            // inside the state's box. Our engine; each costs nothing.
            $forms = [];
            $forms[] = ['q' => $variant . ($withTown ? ", {$town}" : '') . ($withState ? ", {$state}" : '')];
            // The town WITHOUT the state used to be tried here as a loosening.
            // Removed 4 Sep 2026: degrade the granularity, never the context.
            // The bounded form below loosens the name just as far while still
            // being held inside the state's own box, which is the same context
            // by other means. A town name alone is not unique in Malaysia.
            if ($box !== null) { $forms[] = ['q' => $variant, 'viewbox' => implode(',', [$box->min_lng, $box->min_lat, $box->max_lng, $box->max_lat]), 'bounded' => 1]; }

            // our own map engine, several answers, each tested
            try {
                foreach (array_unique(array_map(fn ($f) => json_encode($f), $forms)) as $encoded) {
                $form  = json_decode($encoded, true);
                $query = $form['q'];
                $r = $this->nominatimGet('/search', $form + ['format' => 'json', 'limit' => 5, 'addressdetails' => 1, 'namedetails' => 1,
                    'countrycodes' => strtolower(\App\Services\Geo\Boundaries\Iso3166::iso2($e['country'] ?? 'MYS') ?? 'my')], 10);

                foreach ($r->successful() ? ($r->json() ?: []) : [] as $h) {
                    $c = ['lat' => (float) $h['lat'], 'lng' => (float) $h['lon'], 'display' => (string) ($h['display_name'] ?? ''),
                          'label' => explode(',', (string) ($h['display_name'] ?? $variant))[0], 'state' => $h['address']['state'] ?? null,
                          'country' => $h['address']['country'] ?? null, 'confidence' => 0.7, 'provider' => 'nominatim',
                          'osm_type' => (string) ($h['type'] ?? '')];   // the map's own word for what it is: station, mall, courthouse

                    if ($holds($c, $variant)) {
                        $this->note('variant', $query . (isset($form['bounded']) ? " (inside {$state})" : ''), 'found: ' . mb_substr($c['display'], 0, 70));
                        $this->lastProvider = 'nominatim';

                        return $c;
                    }

                    $this->note('variant', $query, 'refused (' . $why . '): ' . mb_substr($c['label'], 0, 50));

                    // The map's OTHER names for the same object: the heritage
                    // house in Sibu is named in Chinese and, by alt_name, "The
                    // Oldest House At Sungai Sadit" - it stands AT the river
                    // the story names, so its point is the river's.
                    $rest = implode(',', array_slice(explode(',', (string) $c['display']), 1));

                    foreach (['name:en', 'name:ms', 'alt_name', 'alt_name:en', 'alt_name:ms', 'official_name', 'official_name:en', 'old_name'] as $key) {
                        $alias = trim((string) (($h['namedetails'] ?? [])[$key] ?? ''));

                        if ($alias === '' || mb_strtolower($alias) === mb_strtolower($c['label'])) {
                            continue;
                        }

                        $c2 = $c;
                        $c2['display'] = $alias . ($rest !== '' ? ',' . $rest : '');

                        if ($holds($c2, $variant)) {
                            $c['note'] = sprintf('the map calls it %s, also "%s"', $c['label'], $alias);
                            $c['display'] = $alias . ' (' . $c['label'] . ')' . ($rest !== '' ? ',' . $rest : '');
                            $this->note('variant', $query, 'found by its other name "' . mb_substr($alias, 0, 50) . '": ' . mb_substr($c['label'], 0, 50));
                            $this->lastProvider = 'nominatim';

                            return $c;
                        }
                    }
                }
                }
            } catch (\Throwable $x) {
                // the map engine was down for this one; the gazetteer still answers
            }

        }

        // Nothing on the map for any spelling: the gazetteer, every spelling, the same tests.
        // Asked AFTER the whole map pass on purpose: an Overture row 28 km off answered for
        // "Stesen Sri Raya" before the map was asked for "Sri Raya Station", which it has.
        foreach ($variants as $variant) {
            // the gazetteer, same tests
            try {
                foreach ((new GazetteerSearch())->find($variant, $e['country'] ?? 'MYS', $e['state'] ?? null, null, 5) as $g) {
                    $c = $g + ['display' => $g['label'] . ' ' . $g['display']];

                    if ($holds($c, $variant)) {
                        $this->note('variant', $variant, 'found in the gazetteer: ' . mb_substr($g['display'], 0, 70));
                        $this->lastProvider = $g['provider'];

                        return ['lat' => $g['lat'], 'lng' => $g['lng'], 'label' => $g['label'], 'display' => $g['display'],
                            'confidence' => $g['confidence'], 'state' => $g['state'], 'country' => $g['country'], 'provider' => $g['provider']];
                    }
                }
            } catch (\Throwable $x) {
                // nothing from the gazetteer for this spelling
            }
        }
        $this->note('variant', $bare, count($variants) . ' other spelling(s) tried: ' . mb_substr(implode(' / ', $variants), 0, 120));

        // The PARENT institution, the same way: "Galeri 1, Jabatan Muzium
        // Malaysia, Kuala Lumpur" - the gallery is on no map, the department
        // is (as Jabatan Muzium Negara). A part that is a town or a state is
        // an anchor, not a parent.
        if (!$this->inParentPass) {
            $this->inParentPass = true;
            $this->note('variant', 'parent pass', 'parts: ' . implode(' | ', $parts) . '; anchor: ' . ($anchor['name'] ?? '-') . '; state: ' . ($state ?? '-'));

            try {
                for ($i = 1; $i < count($parts); $i++) {
                    $part = $parts[$i];

                    if ($anchor !== null && mb_strtolower($anchor['name']) === mb_strtolower($part)) {
                        break;   // the town: the settlement walk-up places there, and nothing after it is nearer
                    }

                    // a part that IS a country is an anchor; "Jabatan Muzium
                    // Malaysia" merely contains one and is an institution
                    $plain = count(preg_split('/\s+/', trim($part))) <= 2;   // "Jabatan Muzium Malaysia" is an institution, not Malaysia

                    if (mb_strtolower($part) === mb_strtolower((string) $state) || ($plain && PlaceScale::isTooBigToBeNear($part))
                        || ($plain && CountryCode::codeFor($part) !== null) || mb_strlen($part) < 4) {
                        continue;   // ("Jabatan Muzium Malaysia" has no distinctive word by the list's lights, and is still an institution worth asking for)
                    }

                    $parentName = implode(', ', array_slice($parts, $i));
                    $found = $this->tryVariants($parentName);

                    if ($found !== null) {
                        $found['confidence'] = min((float) ($found['confidence'] ?? 0.55), 0.55);
                        $found['precision']  = 'approximate_area';
                        $found['note']       = sprintf('Placed at %s, the institution the story names; %s itself is on no map', $part, $bare);
                        $this->note('variant', $parentName, 'the parent institution stands for it: ' . mb_substr((string) $found['display'], 0, 60));

                        return $found;
                    }
                }
            } finally {
                $this->inParentPass = false;
            }
        }

        // The KIND in the town, last: "Magistrate's Court, Putrajaya" - the
        // owner typed "mahkamah putrajaya" and the map answered its one court
        // building. Only when the town is anchored, the answer's own name is
        // headed by that kind AND carries the town, and there is exactly one
        // such place - two courts would be a guess.
        // A city-state (Putrajaya, Kuala Lumpur, Labuan) is its own town.
        $cityState = $anchor === null && $state !== null && in_array(mb_strtolower($state), ['putrajaya', 'kuala lumpur', 'labuan', 'singapore'], true);
        $townName  = $anchor['name'] ?? ($cityState ? $state : null);

        // Only a name that IS just a kind: "Magistrate's Court" has one
        // qualifying word; "Dewan Sultan Haji Ahmad Shah" is a specific hall
        // and this fallback would hand it the nearest dining hall.
        $nonKind = array_values(array_filter(NameMatch::distinctiveWords($bare), fn ($w) => !NameMatch::isKind($w)));

        if ($townName !== null && count($nonKind) <= 1) {
            $soft  = ['complex', 'kompleks', 'centre', 'center', 'pusat', 'building', 'bangunan', 'tower', 'menara', 'plaza', 'park', 'taman', 'office', 'pejabat',
                      'kampung', 'kampong', 'kg', 'jalan', 'jln', 'lorong', 'lrg', 'sungai', 'sg', 'bukit'];
            $kinds = array_values(array_filter(array_unique(array_map('mb_strtolower', preg_split('/[^\p{L}]+/u', $bare, -1, PREG_SPLIT_NO_EMPTY))),
                fn ($w) => NameMatch::isKind($w) && !in_array($w, $soft, true)));
            $hits  = [];
            $townTokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($townName), -1, PREG_SPLIT_NO_EMPTY);

            foreach ($kinds as $kind) {
                foreach (array_values(array_filter(array_unique([$kind, NameMatch::twinOf($kind)]))) as $word) {
                    try {
                        $r = $this->nominatimGet('/search', ['q' => "{$word}, {$townName}" . ($state !== null && mb_strtolower($state) !== mb_strtolower($townName) ? ", {$state}" : ''), 'format' => 'json', 'limit' => 6, 'addressdetails' => 1,
                            'countrycodes' => strtolower(\App\Services\Geo\Boundaries\Iso3166::iso2($e['country'] ?? 'MYS') ?? 'my')], 10);
                    } catch (\Throwable $x) {
                        continue;
                    }

                    foreach ($r->successful() ? ($r->json() ?: []) : [] as $h) {
                        $own  = explode(',', (string) ($h['display_name'] ?? ''))[0];
                        $head = NameMatch::headKind($own);

                        if ($head === null || !in_array($head, [$kind, NameMatch::twinOf($kind)], true)) { continue; }
                        // its own name must carry the town, EXACTLY: one letter
                        // off let "Maran" match a "Dewan Makan"
                        $ownTokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($own), -1, PREG_SPLIT_NO_EMPTY);

                        if (array_diff($townTokens, $ownTokens) !== []) { continue; }
                        if ($anchor !== null && !$this->nearAnchor((float) $h['lat'], (float) $h['lon'], $anchor)) { continue; }
                        if ($check->verify($stripped, (float) $h['lat'], (float) $h['lon'], $this->home)['verdict'] === 'outside') { continue; }

                        $hits[mb_strtolower($own)] = ['lat' => (float) $h['lat'], 'lng' => (float) $h['lon'], 'display' => (string) $h['display_name'], 'label' => $own,
                            'state' => $h['address']['state'] ?? null, 'country' => $h['address']['country'] ?? null, 'confidence' => 0.5, 'provider' => 'nominatim',
                            'precision' => 'approximate_area', 'note' => sprintf('The story names a %s in %s; the map has one, %s', $kind, $townName, $own)];
                    }
                }
            }

            if (count($hits) === 1) {
                $hit = array_values($hits)[0];
                $this->note('variant', $bare, 'the one ' . implode('/', $kinds) . ' in ' . $townName . ': ' . $hit['label']);
                $this->lastProvider = 'nominatim';

                return $hit;
            }

            if (count($hits) > 1) {
                $this->note('variant', $bare, count($hits) . ' places of that kind in ' . $townName . ' - not choosing: ' . mb_substr(implode(' / ', array_keys($hits)), 0, 90));
            }
        }

        return null;
    }

    /** True while tryVariants is asking about a parent, so a parent does not ask about its own parents forever. */
    private bool $inParentPass = false;

    /** True while the two ends of a "between A and B" name are being placed. */
    private bool $inBetween = false;

    /**
     * Is the point within reach of the district or town the name gave?
     *
     * A district anchor comes with its real bounding box, and the box (a
     * little padded) decides. A TOWN anchor comes with Nominatim's synthetic
     * box - a fixed 0.08 degrees for every place node, 12 km across whether
     * it is Layar or Kajang - which says nothing, so a town holds a place to
     * 8 km of its point. A shop in Seri Kembangan was matched to another
     * shop 15 km away in Kuala Lumpur under the old, looser rule.
     */
    /**
     * The map's hit to anchor on: the TOWN of that name before the district
     * of that name. "Kuala Kangsar, Perak" answered the district first, whose
     * box reaches 39 km out and let an RTC in Sungai Raya stand for a river
     * bank in the town. A town node's box is small, which is the point.
     *
     * @return array{lat: float, lng: float, label: string, bbox: ?array}
     */
    private function anchorHit(string $query, string $countryCode): array
    {
        $r = $this->nominatimGet('/search', ['q' => $query, 'format' => 'json', 'limit' => 5, 'addressdetails' => 0, 'countrycodes' => $countryCode], 10);
        $hits = $r->successful() ? ($r->json() ?: []) : [];

        if ($hits === []) {
            throw new \RuntimeException('no anchor on the map for ' . $query);
        }

        $pick = null;

        foreach ($hits as $h) {
            if (($h['class'] ?? '') === 'place' && in_array((string) ($h['type'] ?? ''), ['city', 'town', 'village', 'hamlet', 'suburb', 'neighbourhood', 'quarter', 'locality'], true)) {
                $pick = $h;
                break;
            }
        }

        $pick ??= $hits[0];
        $bb = $pick['boundingbox'] ?? null;

        // the district of the same name, if the map lists one: "Kg Tanduo
        // Lahad Datu" is 40 km from Lahad Datu town and in Lahad Datu district
        $district = null;

        foreach ($hits as $h) {
            if ($h !== $pick && (($h['class'] ?? '') === 'boundary' || ($h['type'] ?? '') === 'administrative') && is_array($h['boundingbox'] ?? null)) {
                $district = array_map('floatval', $h['boundingbox']);
                break;
            }
        }

        return [
            'lat'   => (float) $pick['lat'],
            'lng'   => (float) $pick['lon'],
            'label' => explode(',', (string) ($pick['display_name'] ?? $query))[0],
            'bbox'  => is_array($bb) && count($bb) === 4 ? [(float) $bb[0], (float) $bb[1], (float) $bb[2], (float) $bb[3]] : null,
            'district_bbox' => $district,
        ];
    }

    private function nearAnchor(float $lat, float $lng, ?array $anchor, ?string $display = null): bool
    {
        if ($anchor === null) {
            return true;
        }

        $bbox = $anchor['bbox'] ?? null;

        // near the town: inside its box padded by 0.1 degree (11 km), or
        // within 8 km of its point when the map gave no box
        if (!empty($bbox)) {
            if ($lat >= $bbox[0] - 0.1 && $lat <= $bbox[1] + 0.1 && $lng >= $bbox[2] - 0.1 && $lng <= $bbox[3] + 0.1) {
                return true;
            }
        } elseif ($this->km($lat, $lng, (float) $anchor['lat'], (float) $anchor['lng']) <= 8.0) {
            return true;
        }

        // in the DISTRICT of that name, and the answer's own address says so:
        // "Kg Tanduo Lahad Datu, Lahad Datu, Sabah" is 40 km from Lahad Datu
        // town; "Rtc Sungai Perak, Sungai Raya, Perak" never mentions Kuala
        // Kangsar and is not the river bank in Kuala Kangsar town
        $d = $anchor['district_bbox'] ?? null;

        if (is_array($d) && count($d) === 4 && $display !== null
            && $lat >= $d[0] && $lat <= $d[1] && $lng >= $d[2] && $lng <= $d[3]
            && str_contains(mb_strtolower($display), mb_strtolower((string) $anchor['name']))) {
            return true;
        }

        return false;
    }

    /** "Dataran Bandaraya" -> "Dataran Bandaraya, Sabah" when the name claims a state. */
    private function withState(string $bare, string $claim): string
    {
        $e = (new BoundaryCheck())->expectation($claim, $this->home);
        $state = $e['state_name'] ?? null;

        if ($state === null || $this->alreadyNamesState($bare, $e)) {
            return $bare;
        }

        return "{$bare}, {$state}";
    }

    /** What the bounded search saw but could not accept - for the person. */
    private array $lastSuggestions = [];

    public function lastSuggestions(): array
    {
        return $this->lastSuggestions;
    }

    /**
     * The search box is the state's own box.
     *
     * A world search for "Dataran Bandaraya" returns the one in Kota Bharu.
     * Bounded to Sabah it cannot. But bounded is not enough - measured on
     * eleven Sabah names, the bounded fuzzy index returned "Kampung Pengkalan
     * Peturu" for "Kampung Pengkalan Abai" and "Simpang Tiga, Kinabatangan"
     * for a T-junction near Kota Belud, both inside Sabah and both wrong. So
     * an answer is accepted only when it passes three tests: the pin is
     * inside the state (polygon), the answer carries the name's distinctive
     * words, and it lies within reach of the district the name gives.
     *
     * Everything inside the state that fails the other two is kept as a
     * suggestion for the person - "Masjid Tanjung Kapor, Kudat" is not the
     * kampung, but it tells the reviewer where to look.
     */
    private function boundedSearch(string $stripped, array $parts, string $bare): ?array
    {
        $check = new BoundaryCheck();
        $e = $check->expectation($stripped, $this->home);

        if ($e['state'] === null || $e['country'] === null) {
            return null;
        }

        $box = DB::table('boundaries')->where('iso3', $e['country'])->where('level', 1)->where('code', $e['state'])
            ->first(['min_lat', 'max_lat', 'min_lng', 'max_lng']);

        if ($box === null) {
            return null;
        }

        $viewbox = implode(',', [$box->min_lng, $box->min_lat, $box->max_lng, $box->max_lat]);
        $anchor  = $this->districtAnchor($parts, $e);

        $candidates = [];

        try {
            $r = $this->nominatimGet('/search', ['q' => $bare, 'format' => 'json', 'limit' => 5, 'addressdetails' => 1,
                    'viewbox' => $viewbox, 'bounded' => 1, 'countrycodes' => strtolower(\App\Services\Geo\Boundaries\Iso3166::iso2($e['country']) ?? 'my')], 12);

            foreach ($r->json() ?: [] as $h) {
                $candidates[] = ['src' => 'nominatim', 'lat' => (float) $h['lat'], 'lng' => (float) $h['lon'],
                    'label' => (string) ($h['display_name'] ?? ''), 'state' => $h['address']['state'] ?? null, 'country' => $h['address']['country'] ?? null];
            }
        } catch (\Throwable $x) {
            $this->note('nominatim', $bare, 'bounded search failed: ' . mb_substr($x->getMessage(), 0, 60));
        }

        try {
            $r = Http::withHeaders(['User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)'])->timeout(12)
                ->get('https://photon.komoot.io/api', ['q' => $bare, 'limit' => 5, 'bbox' => $viewbox]);

            foreach ($r->json('features') ?: [] as $f) {
                $p = $f['properties'] ?? [];
                $candidates[] = ['src' => 'photon', 'lat' => (float) $f['geometry']['coordinates'][1], 'lng' => (float) $f['geometry']['coordinates'][0],
                    'label' => implode(', ', array_filter([$p['name'] ?? null, $p['city'] ?? $p['county'] ?? null, $p['state'] ?? null])),
                    'state' => $p['state'] ?? null, 'country' => $p['country'] ?? null];
            }
        } catch (\Throwable $x) {
            $this->note('photon', $bare, 'bounded search failed: ' . mb_substr($x->getMessage(), 0, 60));
        }

        // Our own gazetteer, inside the same box.
        try {
            foreach ((new GazetteerSearch())->find($parts[0] ?? $bare, $e['country'], $e['state'],
                [(float) $box->min_lat, (float) $box->max_lat, (float) $box->min_lng, (float) $box->max_lng]) as $g) {
                $candidates[] = ['src' => $g['provider'], 'lat' => $g['lat'], 'lng' => $g['lng'], 'label' => $g['display'],
                    'state' => $g['state'], 'country' => $g['country']];
            }
        } catch (\Throwable $x) {
            $this->note('gazetteer', $bare, 'search failed: ' . mb_substr($x->getMessage(), 0, 60));
        }

        // The one index that is not OpenStreetMap, inside the same box.
        try {
            foreach ((new HereGeocoder())->search($bare, strtolower(\App\Services\Geo\Boundaries\Iso3166::iso2($e['country']) ?? 'my'),
                [(float) $box->min_lat, (float) $box->max_lat, (float) $box->min_lng, (float) $box->max_lng]) as $h) {
                $candidates[] = ['src' => 'here', 'lat' => $h['lat'], 'lng' => $h['lng'], 'label' => $h['display'] ?: $h['label'],
                    'state' => $h['state'], 'country' => $h['country']];
            }
        } catch (GeocodingRateLimitedException $x) {
            $this->note('here', $bare, 'rate limited');
        } catch (\Throwable $x) {
            $this->note('here', $bare, 'bounded search failed: ' . mb_substr($x->getMessage(), 0, 60));
        }

        foreach ($candidates as $c) {
            $v = $check->verify($stripped, $c['lat'], $c['lng'], $this->home);

            if ($v['verdict'] === 'outside') {
                continue;   // not even in the state: not worth suggesting
            }

            $nameOk = NameMatch::holds($parts[0] ?? $bare, $c['label']);
            $near   = $anchor === null ? null : $this->km($c['lat'], $c['lng'], $anchor['lat'], $anchor['lng']);
            $nearOk = $this->nearAnchor((float) $c['lat'], (float) $c['lng'], $anchor, (string) ($c['display'] ?? ''));

            if ($nameOk && $nearOk) {
                $this->note($c['src'], "{$bare} (inside {$e['state_name']})", 'found: ' . mb_substr($c['label'], 0, 60)
                    . ($anchor ? sprintf(', %.0f km from %s', $near, $anchor['name']) : ''));
                $this->lastProvider = $c['src'] . '_bounded';

                return ['lat' => $c['lat'], 'lng' => $c['lng'], 'label' => explode(',', $c['label'])[0],
                    'display' => $c['label'], 'confidence' => 0.6, 'state' => $c['state'], 'country' => $c['country'], 'provider' => $c['src'] . '_bounded'];
            }

            $why = !$nameOk ? 'a different name' : sprintf('%.0f km from %s', $near, $anchor['name']);
            $this->lastSuggestions[] = ['src' => $c['src'], 'lat' => round($c['lat'], 6), 'lng' => round($c['lng'], 6),
                'label' => mb_substr($c['label'], 0, 120), 'why' => $why];
        }

        $this->note('bounded', $bare, count($candidates) === 0
            ? "nothing inside {$e['state_name']}"
            : count($candidates) . " inside {$e['state_name']}, none carrying the name" . ($anchor ? " within 30 km of {$anchor['name']}" : ''));

        return null;
    }

    /**
     * A point for the district or town the name gives, so a bounded answer
     * can be held to it. "Kota Belud" resolves; the junction on its road does
     * not - but the junction must be near Kota Belud, or it is not that one.
     */
    private function districtAnchor(array $parts, array $e): ?array
    {
        // The components after the place itself, NARROWEST FIRST - the one
        // right after the place is the town, the last is the state. It used
        // to walk from the end, so "Taman Mawar, Machap Baru, Alor Gajah,
        // Melaka" was anchored to Alor Gajah district, and once to Melaka
        // itself (the polygon calls it Malacca, so the name test missed it).
        for ($i = 1; $i < count($parts); $i++) {
            $part = trim($parts[$i]);

            if ($part === '' || mb_strtolower($part) === mb_strtolower((string) $e['state_name'])
                || CountryCode::codeFor($part) !== null || PlaceScale::isTooBigToBeNear($part)) {
                continue;
            }

            // Nominatim first, for the district's BOX. The cache holds only a
            // point, and a district's point is its centroid - "Kudat" cached
            // that way sat 52 km from Kudat town and refused a hit that was in
            // Kudat. The cache is the fallback, not the answer.
            // The map's spelling first (Teluk for Telok, Kampung for Kampong):
            // "Telok Sengat" alone answered a shop at a FELDA scheme, "Teluk
            // Sengat" the village. No state claimed: the part alone, in the country.
            $spellings = array_values(array_unique([
                preg_replace_callback('/\b(telok|tanjong|kampong|sungei|bahru|ayer)\b/iu', fn ($m) => ['telok' => 'Teluk', 'tanjong' => 'Tanjung', 'kampong' => 'Kampung', 'sungei' => 'Sungai', 'bahru' => 'Baharu', 'ayer' => 'Air'][mb_strtolower($m[1])], $part),
                $part,
            ]));
            $suffix = ($e['state_name'] ?? null) !== null ? ', ' . $e['state_name'] : '';

            try {
                foreach ($spellings as $spelling) {
                    try {
                        $r = $this->anchorHit($spelling . $suffix, strtolower(\App\Services\Geo\Boundaries\Iso3166::iso2($e['country'] ?? 'MYS') ?? 'my'));
                    } catch (\Throwable $x) {
                        continue;
                    }

                    // the anchor must BE the part asked for: "Lembah Jetty, Perak"
                    // answered some jetty elsewhere in Perak and the real town,
                    // Kuala Kangsar, one part later, was never reached
                    if (isset($r['label']) && !NameMatch::holds($spelling, (string) $r['label'])) {
                        continue;
                    }

                    if ((new BoundaryCheck())->verify($spelling . $suffix, $r['lat'], $r['lng'], $this->home)['verdict'] !== 'outside') {
                        return ['name' => $part, 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'bbox' => $r['bbox'] ?? null, 'district_bbox' => $r['district_bbox'] ?? null];
                    }
                }

                throw new \RuntimeException('no anchor on the map');
            } catch (\Throwable $x) {
                // Nominatim had nothing (or was busy): the cached point, if any.
                $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', $part)));
                $hit = DB::table('geocode_cache')->where('place_key', $key)->where('status', 'success')->first(['lat', 'lng']);

                if ($hit && $hit->lat !== null) {
                    return ['name' => $part, 'lat' => (float) $hit->lat, 'lng' => (float) $hit->lng, 'bbox' => null];
                }
            }
        }

        return null;
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = ($lat2 - $lat1) * 111.32;
        $dLng = ($lng2 - $lng1) * 111.32 * cos(deg2rad(($lat1 + $lat2) / 2));

        return sqrt($dLat * $dLat + $dLng * $dLng);
    }

    /**
     * The town, then the district, each with the state on the end.
     *
     * The name already carries them - "Masjid Jamek An-Nur, Kuantan" gives
     * Kuantan, and the boundary layer gives Pahang - so no model is asked and
     * nothing is guessed. Narrowest part first, widening one part at a time,
     * which is the same walk the rest of the search makes.
     *
     * ⛔ Never called before the precise passes have all failed. Called early
     * it would answer "Kuantan" for a hall in Kuantan and stop the search that
     * would have found the hall.
     */
    /**
     * Above this many people, a place is national news rather than a pin.
     *
     * It applies ONLY where it is set - in the last rung, when nothing narrower
     * could be found. Owner, 4 Sep 2026: "this rules only applies when there is
     * no lower level location to pinpoint." A story that resolves to an exact
     * venue inside Kuala Lumpur is still a local story about that venue.
     *
     * THE NUMBER IS A JUDGEMENT, AND IT IS THE OWNER'S TO MOVE. He first said
     * 500,000, then looked at what that did and said "Petaling jaya is not a
     * state. So it is still a local". Both cannot hold: PJ is 807,879. One
     * million is the only line that satisfies every verdict he actually gave -
     *
     *   Kuala Lumpur   1,453,975  national   (he said so)
     *   Petaling Jaya    807,879  local      (he said so)
     *   Shenzhen      17,494,398  national   (he said so)
     *
     * - and it leaves Johor Bahru, Ipoh, Shah Alam, Subang Jaya, Kuantan and
     * Kota Bharu local, which is where "not a state" points. Change this one
     * number to move the line; nothing else needs touching.
     */
    private const TOO_BIG_TO_BE_LOCAL = 1000000;

    private function townRung(string $placeName): ?array
    {
        // "X in Y" is a chain too, written without the comma. The model writes
        // it eight times in a thousand - "China Masters in Shenzhen, 中国",
        // "Spark Bar in Inanam" - and read as one part it hides the only place
        // in the name. Done HERE and nowhere else: this is the last resort, so
        // loosening the name cannot cost a more precise answer that would
        // otherwise have been found.
        $chain = preg_replace('/\s+\bin\b\s+/iu', ', ', $placeName, 1);
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $chain)), fn ($p) => $p !== ''));

        if (count($parts) < 2) {
            return null;   // no chain to walk: nothing was named but the place itself
        }

        $e     = (new BoundaryCheck())->expectation($placeName, $this->home);
        $state = $e['state_name'] ?? null;

        // The context that goes on the end of every query. At home that is the
        // state, which the boundary layer knows even when the text omits it.
        //
        // Abroad we have no state, and refusing there threw away pins we could
        // have kept: "China Masters in Shenzhen, 中国" is an EVENT, not a
        // place, but the name still says Shenzhen. So the widest part the name
        // itself gives - the country - becomes the context, and the walk-up
        // works the same way. Degrade the granularity, never the context.
        $context = $state;

        if ($context === null && count($parts) >= 3) {
            // No state known, but the name itself names something wider than
            // the rung: "..., Shenzhen, 中国" gives 中国.
            $context = (string) $parts[count($parts) - 1];
        }

        // Still nothing? Then the COUNTRY is the context, and it is applied as
        // the search's country filter rather than as words on the end.
        //
        // ⛔ This case is not rare and refusing it was wrong. The boundary
        // layer knows the states and their districts, but not every town:
        // "Kedai X, Petaling Jaya" yields no state, so the rung declined and a
        // Petaling Jaya story got no pin at all - worse than the town pin the
        // owner asked for. The country filter still holds the search to
        // Malaysia, and the boundary check below still has to pass, so the
        // context is kept; only its form changes.
        $countryOnly = $context === null || $context === '';

        $iso2    = \App\Services\Geo\Boundaries\Iso3166::iso2($e['country'] ?? 'MYS');
        $country = $iso2 === null ? null : strtolower($iso2);

        foreach (array_slice($parts, 1) as $part) {
            $low = mb_strtolower($part);

            if (($context !== null && $low === mb_strtolower($context)) || mb_strlen($part) < 3) {
                continue;   // the context is the end of the query, not a rung on it
            }

            if ($countryOnly && $country === null) {
                return null;   // no state, no wider name, no country filter: nothing holds the query
            }

            if (preg_match('/^(jalan|jln|lorong|lrg|lebuhraya|highway|road|km)\b/iu', $part)) {
                continue;   // a road is a line, not a town
            }

            $query = $countryOnly ? $part : "{$part}, {$context}";

            try {
                $r = $this->nominatimGet('/search', ['q' => $query, 'format' => 'json', 'limit' => 5, 'addressdetails' => 1]
                    + ($country === null ? [] : ['countrycodes' => $country]), 10);
            } catch (\Throwable $x) {
                continue;
            }

            $hits = $r->successful() ? ($r->json() ?: []) : [];

            if ($hits === []) {
                $this->note('town rung', $query, 'nothing on the map');

                // preferSettlement() reads $results[0] and returns array, not
                // ?array - handing it an empty list is a crash, not a miss.
                continue;
            }

            // the settlement before the boundary: an administrative polygon's
            // centre is not where the town is (Kuantan sat 32 km out that way)
            $top = $this->preferSettlement($query, $hits);

            // A CITY TOO BIG TO BE "NEAR" IS NOT A PIN - IT IS NATIONAL NEWS.
            //
            // Owner, 4 Sep 2026: "Shenzhen is a very large city. Then i would
            // classify it as national. Because my other rules says if a news
            // belong to a large city. Make it national news." And, on the same
            // story: "if the article didnt mention [the stadium], then leaving
            // it as Shenzhen is correct."
            //
            // Both hold at once, because the NAME and the PIN are separate.
            // canonical_place_name still reads "China Masters in Shenzhen", so
            // the reader is told where it happened; what we decline to do is
            // drop a local pin in the middle of seventeen million people and
            // call it someone's neighbourhood. With no pin the story is
            // national, found under its topic - which is what he asked for.
            //
            // The bar is the owner's, set 4 Sep 2026: "anything below 500,000
            // population is local news. Above 500,000 consider it as national
            // news. this rules only applies when there is no lower level
            // location to pinpoint." That last clause is this method - it runs
            // only after every precise pass has failed.
            // ⛔ ONLY ASK THE POPULATION OF SOMETHING THAT IS ACTUALLY A PLACE
            // OF SIZE. GeoNames attaches a DISTRICT's count to whatever name
            // sits in that district, and the gazetteer lookup below matches on
            // name and a rough box, so it will happily hand back a number that
            // belongs to somewhere else entirely. Measured 4 Sep 2026:
            //
            //   Selayang Baru Utara   542,409   the map says: a BUS STOP
            //   Bukit Rahman Putra    607,000   the map says: a HOUSING ESTATE
            //   Kampung Baru Subang   833,571   the map says: a HAMLET
            //   Petaling Jaya         807,879   the map says: a city  <- real
            //
            // Nationalising a bus stop because a district around it holds half
            // a million people would be the worst kind of wrong: silent, and
            // it removes the story from the very readers it is for. So the
            // rule runs only where the map itself agrees this is a city, a
            // town, or an administrative area - never a hamlet, a suburb, a
            // housing estate or a bus stop.
            $class = (string) ($top['class'] ?? '');
            $type  = (string) ($top['type'] ?? '');
            $isPlaceOfSize = ($class === 'place' && in_array($type, ['city', 'town', 'municipality'], true))
                || ($class === 'boundary' && $type === 'administrative');

            $label   = explode(',', (string) ($top['display_name'] ?? $part))[0];
            // Two keys, because the map and the table need not agree on the
            // script: asked for "Shenzhen" the map answers "深圳市", whose key
            // matches no row, and a city of 17.5 million was pinned as local.
            $nameKeys = array_values(array_unique([
                \App\Services\Geo\GazetteerSearch::key($label),
                \App\Services\Geo\GazetteerSearch::key($part),
            ]));
            $nameKey = $nameKeys[0];
            $people  = null;

            if ($isPlaceOfSize) {
                // world_cities holds all 34,135 places of 15,000 people or
                // more, indexed by country - so this answers for Shenzhen and
                // Jakarta as readily as for Ipoh, which the Malaysia-only
                // gazetteer could not. Matched on the name AND the position,
                // because a name on its own repeats across the world.
                $people = DB::table('world_cities')
                    ->whereIn('name_key', $nameKeys)
                    ->whereBetween('lat', [(float) $top['lat'] - 0.05, (float) $top['lat'] + 0.05])
                    ->whereBetween('lng', [(float) $top['lon'] - 0.05, (float) $top['lon'] + 0.05])
                    ->max('population');

                // the older Malaysian gazetteer still answers for places under
                // 15,000 people, which cities15000 does not carry at all
                if ($people === null) {
                    $people = DB::table('gazetteer')->where('source', 'geonames')->whereNotNull('population')
                        ->whereIn('name_key', $nameKeys)
                        ->whereBetween('lat', [(float) $top['lat'] - 0.05, (float) $top['lat'] + 0.05])
                        ->whereBetween('lng', [(float) $top['lon'] - 0.05, (float) $top['lon'] + 0.05])
                        ->max('population');
                }
            }

            if ($people !== null && (int) $people > self::TOO_BIG_TO_BE_LOCAL) {
                $this->note('town rung', $query, sprintf('%s has %s people - too big for anyone to be near, so this story is national, not pinned',
                    $label, number_format((int) $people)));

                return null;   // wider would only be bigger
            }

            $hit = ['lat' => (float) $top['lat'], 'lng' => (float) $top['lon'], 'label' => explode(',', (string) ($top['display_name'] ?? $part))[0],
                    'display' => (string) ($top['display_name'] ?? $query), 'confidence' => 0.4,
                    'state' => $top['address']['state'] ?? $state, 'country' => $top['address']['country'] ?? null,
                    'provider' => 'town_rung', 'precision' => 'approximate_area',
                    'note' => sprintf('Nothing narrower could be found, so the story is placed at %s, the wider place it names, inside %s', $part, $context)];

            if (!$this->within($placeName, $hit, 'town rung')) {
                continue;
            }

            $this->note('town rung', $query, 'placed at the town the name gives: ' . mb_substr($hit['label'], 0, 50));
            $this->lastProvider = 'town_rung';

            return $hit;
        }

        return null;
    }

    /**
     * Does this text already say which state it is in - in ANY of its names?
     *
     * The boundary layer answers with the ENGLISH name. A Malaysian story
     * writes the Malay one. Comparing the two as plain strings says "no", so
     * the state was appended a second time and the query became "Pantai
     * Klebang, Melaka, Malacca" - a half-translated address the map answers
     * worse than the original. Measured 4 Sep 2026: it moved three of sixty
     * pins, one of them 4.9 km.
     *
     * The alias table already holds Melaka for Malacca and Pulau Pinang for
     * Penang, so the fix is to ask it. District aliases are excluded on
     * purpose: "Jasin" is a district of Malacca, and a story that says Jasin
     * has NOT said which state that is - it still needs the state appended.
     *
     * @param array<string, mixed> $e the boundary expectation for the name
     */
    private function alreadyNamesState(string $text, array $e): bool
    {
        $state = $e['state_name'] ?? null;

        if ($state === null) {
            return false;
        }

        $low = mb_strtolower($text);

        if (str_contains($low, mb_strtolower($state))) {
            return true;
        }

        $code    = $e['state'] ?? null;
        $country = $e['country'] ?? null;

        if ($code === null || $country === null) {
            return false;
        }

        static $cache = [];
        $key = $country . '|' . $code;

        if (!array_key_exists($key, $cache)) {
            $cache[$key] = DB::table('boundary_aliases')
                ->where('iso3', $country)->where('level', 1)->where('code', $code)
                ->where(function ($q) { $q->whereNull('language')->orWhere('language', '<>', 'district'); })
                ->pluck('alias_key')->all();
        }

        foreach ($cache[$key] as $alias) {
            if ($alias !== '' && str_contains($low, (string) $alias)) {
                return true;
            }
        }

        return false;
    }

    private function note(string $step, string $query, string $outcome): void
    {
        $this->lastAttempts[] = ['step' => $step, 'query' => $query, 'outcome' => $outcome];
    }

    /**
     * Is this a place the Malaysian gazetteer knows?
     *
     * location_aliases is the list of Malaysian places this pipeline has been
     * taught. A name in it is unambiguously Malaysian, so the query can say so
     * - which is what stops Klang resolving to a commune in France. A name not
     * in it is resolved as written, which is what lets Tokyo be in Japan.
     */
    private function isKnownMalaysianPlace(string $placeName): bool
    {
        $key = mb_strtolower(trim($placeName));

        if ($key === '') {
            return false;
        }

        if (str_contains($key, 'malaysia')) {
            return true;
        }

        static $known = null;

        if ($known === null) {
            $known = [];

            try {
                $rows = DB::table('location_aliases')->where('is_active', true)
                    ->get(['alias_text', 'canonical_name']);

                foreach ($rows as $row) {
                    $known[mb_strtolower($row->alias_text)] = true;
                    $known[mb_strtolower($row->canonical_name)] = true;
                }
            } catch (\Throwable $e) {
                $known = [];
            }
        }

        if (isset($known[$key])) {
            return true;
        }

        // "Sentul, Kuala Lumpur" is Malaysian if any of its parts are.
        foreach (explode(',', $key) as $part) {
            if (isset($known[trim($part)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coordinates to the name of the town they are in.
     *
     * The browser hands us a point; the feed wants a place. Rather than teach
     * every stage downstream to accept a latitude, the point is turned into the
     * same kind of name a reader would have typed - so the URL is shareable,
     * the heading reads "News near Petaling Jaya", and everything after this
     * behaves exactly as if they had typed it.
     *
     * Zoom 14 asks for the town rather than the street. A reader who allows
     * their location wants the news near them, not their own address in the
     * address bar - and a house number in a shared link is more than they
     * agreed to give.
     */
    public function reverse(float $lat, float $lng): ?string
    {
        try {
            $response = $this->nominatimGet('/reverse', [
                'lat'            => $lat,
                'lon'            => $lng,
                'format'         => 'json',
                'zoom'           => 14,
                'addressdetails' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $address = $response->json('address') ?? [];
        } catch (\Throwable $e) {
            Log::warning('Reverse geocode failed', ['error' => $e->getMessage()]);

            return null;
        }

        // Narrowest first. A reader in Bangsar should be told Bangsar, not
        // Kuala Lumpur - the whole point of the feed is the difference.
        $localityKeys = ['suburb', 'neighbourhood', 'quarter', 'village', 'town', 'city_district', 'city', 'county'];
        $locality = null;

        foreach ($localityKeys as $key) {
            if (!empty($address[$key])) {
                $locality = $address[$key];
                break;
            }
        }

        if ($locality === null) {
            return null;
        }

        $state = $address['state'] ?? null;

        // The state qualifies the name for the same reason it does everywhere
        // else here: more than one Malaysian town shares a name.
        return $state && stripos($locality, (string) $state) === false
            ? $locality . ', ' . $state
            : $locality;
    }

    /**
     * The same OpenStreetMap data, from a different front door.
     *
     * Photon is Komoot's OSM search: free, no key, and not sharing Nominatim's
     * rate budget. It is the stand-in when Nominatim says 429, not a
     * replacement - Nominatim's structured address is what the state and
     * country completion relies on, and Photon's shape is different.
     *
     * Returns null rather than throwing: this is already the fallback, and a
     * failure here means the caller should carry on failing as it was.
     */
    private function geocodePhoton(string $placeName): ?array
    {
        $this->throttle();

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)',
                'Accept'     => 'application/json',
            ])->timeout(12)->get('https://photon.komoot.io/api', [
                'q'     => $placeName,
                'limit' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $feature = $response->json('features.0');
        } catch (\Throwable $e) {
            Log::warning('Photon lookup failed', ['place' => $placeName, 'error' => $e->getMessage()]);

            return null;
        }

        if (!is_array($feature)) {
            return null;
        }

        // GeoJSON is [longitude, latitude]. The wrong way round from every
        // other coordinate in this codebase, and silently plausible when
        // swapped - 3.1,101.7 is Kuala Lumpur; 101.7,3.1 is nowhere on land.
        $lng = $feature['geometry']['coordinates'][0] ?? null;
        $lat = $feature['geometry']['coordinates'][1] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        $props = $feature['properties'] ?? [];

        return [
            'lat'        => (float) $lat,
            'lng'        => (float) $lng,
            'label'      => $props['name'] ?? $placeName,
            'display'    => implode(', ', array_filter([$props['name'] ?? null, $props['city'] ?? $props['county'] ?? null, $props['state'] ?? null, $props['country'] ?? null])),
            // Below a direct Nominatim hit: a second opinion, taken because the
            // first was unavailable rather than because it was better.
            'confidence' => 0.6,
            'state'      => $props['state'] ?? null,
            'country'    => $props['country'] ?? null,
        ];
    }

    /**
     * @param ?string $country ISO2 to search in; null = the country the name
     *                         itself gives (or the home default); '' = the world
     */

    /**
     * The town, not the district that shares its name.
     *
     * ⛔ Measured 4 Sep 2026: "Kuantan, Pahang" put four live stories 32 km from
     * Kuantan. The map answers that name twice - a `boundary/administrative`
     * record whose point is the DISTRICT centroid, out in the forest, and a
     * `place/city` record on the town itself. Asking for one result took
     * whichever the map ranked first, and for a district that is the boundary.
     *
     * A reader in Kuantan was therefore 32 km from our pin, and every "near me"
     * distance for that story was wrong by the width of the district.
     *
     * The swap is deliberately narrow: it only fires when the top hit is an
     * administrative boundary AND a settlement of the same name is in the list.
     * A venue, a road or a state keeps the answer it had, and the name is still
     * checked afterwards by the caller.
     *
     * @param array<int, array> $results
     */
    private function preferSettlement(string $query, array $results): array
    {
        $top = $results[0];

        if (count($results) < 2) {
            return $top;
        }

        $isBoundary = ($top['class'] ?? '') === 'boundary' || ($top['type'] ?? '') === 'administrative';

        if (!$isBoundary) {
            return $top;
        }

        $bare = mb_strtolower(trim((string) (explode(',', $query)[0] ?? $query)));

        foreach ($results as $hit) {
            $isSettlement = ($hit['class'] ?? '') === 'place'
                && in_array((string) ($hit['type'] ?? ''), ['city', 'town', 'village', 'hamlet', 'suburb', 'municipality'], true);

            if (!$isSettlement) {
                continue;
            }

            $name = mb_strtolower(trim((string) (explode(',', (string) ($hit['display_name'] ?? ''))[0] ?? '')));

            if ($name !== '' && $name === $bare) {
                Log::info('Geocoding took the town over the district of the same name', [
                    'place' => $query,
                    'district' => $top['lat'] . ',' . $top['lon'],
                    'town'     => $hit['lat'] . ',' . $hit['lon'],
                ]);

                return $hit;
            }
        }

        return $top;
    }

    private function queryNominatim(string $query, ?string $country = null): array
    {
        $response = $this->nominatimGet('/search', [
            'q'              => $query,
            'format'         => 'json',
            // Five, not one, so the town can be preferred over the district of
            // the same name. See the pick below.
            'limit'          => 5,
            'addressdetails' => 1,
            // Search inside the country the name gives. Unconstrained, "Bidur,
            // Nuwakot district, Nepal" matched Bidor in PERAK on the first word
            // - a real town, a real answer, and four thousand kilometres from
            // the story. Malaysia is the default because nearly every story is
            // Malaysian; only a country written in the name overrides it.
            'countrycodes'   => $country === '' ? null : ($country ?? CountryCode::forPlace($query, $this->home)),
        ]);

        $placeName = $query;

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

        $top = $this->preferSettlement($query, $results);
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
            'osm_type' => (string) ($top['type'] ?? $hit['type'] ?? $result['type'] ?? ''),
            'type'  => $top['type'] ?? null,
        ]);

        return [
            'lat'        => $lat,
            'lng'        => $lng,
            'label'      => $label,
            'display'    => (string) ($top['display_name'] ?? ''),
            'confidence' => $confidence,
            // The request has always asked for addressdetails; until now the
            // reply was read for coordinates and the rest discarded. The state
            // is what turns "Tasik Kenyir" into a name that means one place.
            'state'      => $top['address']['state'] ?? null,
            'country'    => $top['address']['country'] ?? null,
            // south, north, west, east - the extent of what was matched. A
            // district's box is what "near Kudat" means; its centroid is 50 km
            // from Kudat town.
            'bbox'       => isset($top['boundingbox']) ? array_map('floatval', $top['boundingbox']) : null,
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
