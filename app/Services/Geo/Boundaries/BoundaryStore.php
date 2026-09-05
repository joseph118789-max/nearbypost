<?php

namespace App\Services\Geo\Boundaries;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The boundaries, ready to answer two questions.
 *
 *   locate(lat, lng)          Which country, and which state, is this point in?
 *   area(iso3, level, name)   Which area does this name mean, in this country?
 *
 * Only the polygons that could contain the point are ever decoded.
 *
 * The first version read every country's geometry into memory and kept it -
 * fine on the command line, where memory is unlimited, and a fatal error on
 * the web, where php-fpm allows 128 MB: the first page to call locate() died
 * with "Allowed memory size exhausted", and every render I had done to check
 * it had run on the command line. So now the database does the narrowing.
 * Every row carries its bounding box, indexed; a point asks for the rows
 * whose box contains it - one or two, out of ten thousand - and only those
 * are decoded. A small cache of decoded polygons keeps the geocoder's
 * fifty-story runs from re-decoding Malaysia fifty times.
 */
class BoundaryStore
{
    /**
     * How far offshore a point may be and still belong to the coast it is
     * next to. The boundary data is from 2017; the Sabah International
     * Convention Centre stands on land reclaimed after that, and every
     * beach, jetty and port sits on the edge the simplification trimmed.
     */
    public const COASTAL_KM = 3.0;

    /** Decoded polygons, by "iso3:level:code". Capped; see remember(). */
    private static array $decoded = [];

    private const DECODED_CAP = 16;

    /** Names per (iso3, level): light, no geometry. */
    private static array $names = [];

    public function __construct(private ?BoundaryLoader $loader = null)
    {
        $this->loader ??= new BoundaryLoader();
    }

    // ── which country / state is this point in ──────────────────────────

    /**
     * @return array{country: ?string, country_name: ?string, state: ?string, state_name: ?string,
     *               city: ?string, city_name: ?string, coastal: bool}
     */
    public function locate(float $lat, float $lng): array
    {
        $out = ['country' => null, 'country_name' => null, 'state' => null, 'state_name' => null,
                'city' => null, 'city_name' => null, 'coastal' => false];

        $country = $this->containing($lat, $lng, 0, null);

        if ($country === null) {
            // On no country at all. The nearest coast within reach, if any.
            $country = $this->nearest($lat, $lng, 0, null, self::COASTAL_KM);

            if ($country === null) {
                return $out;
            }

            $out['coastal'] = true;
        }

        $out['country'] = $country['code']; $out['country_name'] = $country['name'];

        $state = $this->containing($lat, $lng, 1, $country['code'])
            ?? $this->nearest($lat, $lng, 1, $country['code'], self::COASTAL_KM, $out['coastal']);

        if ($state === null) {
            return $out;
        }

        $out['state'] = $state['code']; $out['state_name'] = $state['name'];

        // The district, where the country has been loaded to that depth.
        // Never fetched on demand - that is the loader's decision.
        $city = $this->containing($lat, $lng, 2, $country['code'], $state['code'])
            ?? ($out['coastal'] ? $this->nearest($lat, $lng, 2, $country['code'], self::COASTAL_KM, true, $state['code']) : null);

        if ($city !== null) {
            $out['city'] = $city['code']; $out['city_name'] = $city['name'];
        }

        return $out;
    }

    /** Is the point inside this one area? Null when the area is unknown. */
    public function inside(float $lat, float $lng, string $iso3, int $level, string $code): ?bool
    {
        $row = DB::table('boundaries')->where('iso3', strtoupper($iso3))->where('level', $level)->where('code', $code)
            ->first(['iso3', 'level', 'code', 'name', 'min_lat', 'max_lat', 'min_lng', 'max_lng']);

        if ($row === null) {
            return null;
        }

        if ($lat >= $row->min_lat && $lat <= $row->max_lat && $lng >= $row->min_lng && $lng <= $row->max_lng
            && GeoJsonPoint::insideJson($lat, $lng, $this->geometryJson($row))) {
            return true;
        }

        // ⛔ THE BOX HAS TO GATE THE COASTAL CHECK TOO, OR THIS METHOD WALKS A
        // WHOLE CONTINENT TO SAY "no".
        //
        // Without this guard the fall-through below measured the distance from
        // the point to the edge of the polygon whatever the point was - and
        // GeoJsonPoint walks the GeoJSON one character at a time. Nunavut is
        // 13 MB and 319,451 vertices; Canada is 12 MB. Testing a New York
        // stadium against them killed the process:
        //
        //   Maximum execution time of 30 seconds exceeded
        //   at app/Services/Geo/Boundaries/GeoJsonPoint.php:131
        //
        // Twelve of those on 4 Sep 2026, all in the geocoding pass, each one
        // taking the whole run down with it.
        //
        // containing() and nearest() in this same file already gate on the box
        // in SQL, and nearest() pads it by the tolerance exactly like this.
        // inside() was the one path that did not.
        $pad = self::COASTAL_KM / 111.0 * 1.2;

        if ($lat < $row->min_lat - $pad || $lat > $row->max_lat + $pad
            || $lng < $row->min_lng - $pad || $lng > $row->max_lng + $pad) {
            return false;   // neither inside the area nor within reach of its coast
        }

        // Just off this area's coast counts as this area - the same
        // tolerance locate() applies, so the two cannot disagree.
        $d = GeoJsonPoint::distanceToEdgeKmJson($lat, $lng, $this->geometryJson($row), self::COASTAL_KM);

        return $d !== null && $d <= self::COASTAL_KM;
    }

    // ── which area does this name mean ──────────────────────────────────

    /** @return ?array{code: string, name: string} */
    public function area(string $iso3, int $level, string $name): ?array
    {
        $iso3 = strtoupper($iso3);
        $key  = BoundaryLoader::key($name);

        if ($key === '') {
            return null;
        }

        foreach ($this->names($iso3, $level) as $row) {
            if ($row['name_key'] === $key) {
                return ['code' => $row['code'], 'name' => $row['name']];
            }
        }

        $alias = DB::table('boundary_aliases')->where('iso3', $iso3)->where('level', $level)->where('alias_key', $key)->first(['code']);

        if ($alias) {
            foreach ($this->names($iso3, $level) as $row) {
                if ($row['code'] === $alias->code) {
                    return ['code' => $row['code'], 'name' => $row['name']];
                }
            }
        }

        return null;
    }

    public function hasCountry(string $iso3): bool
    {
        return $this->names(strtoupper($iso3), 0) !== [];
    }

    public function hasStates(string $iso3): bool
    {
        return $this->names(strtoupper($iso3), 1) !== [];
    }

    // ── the narrowing ───────────────────────────────────────────────────

    /** The area at this level whose polygon contains the point, or null. */
    private function containing(float $lat, float $lng, int $level, ?string $iso3, ?string $parent = null): ?array
    {
        $q = DB::table('boundaries')->where('level', $level)
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->orderBy('vertices');

        if ($iso3 !== null)   $q->where('iso3', $iso3);
        if ($parent !== null) $q->where('parent_code', $parent);

        foreach ($q->get(['iso3', 'level', 'code', 'name', 'min_lat', 'max_lat', 'min_lng', 'max_lng']) as $row) {
            if (GeoJsonPoint::insideJson($lat, $lng, $this->geometryJson($row))) {
                return ['code' => $row->code, 'name' => $row->name];
            }
        }

        return null;
    }

    /**
     * The nearest area's edge within $maxKm, or null. Candidates are the rows
     * whose box, padded by the tolerance, contains the point.
     */
    private function nearest(float $lat, float $lng, int $level, ?string $iso3, float $maxKm, bool $force = true, ?string $parent = null): ?array
    {
        if (!$force) {
            return null;
        }

        $pad = $maxKm / 111.0 * 1.2;

        $q = DB::table('boundaries')->where('level', $level)
            ->where('min_lat', '<=', $lat + $pad)->where('max_lat', '>=', $lat - $pad)
            ->where('min_lng', '<=', $lng + $pad)->where('max_lng', '>=', $lng - $pad);

        if ($iso3 !== null)   $q->where('iso3', $iso3);
        if ($parent !== null) $q->where('parent_code', $parent);

        $best = null; $bestKm = $maxKm;

        foreach ($q->get(['iso3', 'level', 'code', 'name', 'min_lat', 'max_lat', 'min_lng', 'max_lng']) as $row) {
            $d = GeoJsonPoint::distanceToEdgeKmJson($lat, $lng, $this->geometryJson($row), $bestKm);

            if ($d !== null && $d < $bestKm) {
                $bestKm = $d; $best = ['code' => $row->code, 'name' => $row->name];
            }
        }

        return $best;
    }

    /**
     * One area's polygon as TEXT, kept while it is being used. Never decoded:
     * the tests walk the text. See GeoJsonPoint::insideJson().
     */
    private function geometryJson(object $row): string
    {
        $k = "{$row->iso3}:{$row->level}:{$row->code}";

        if (!isset(self::$decoded[$k])) {
            if (count(self::$decoded) >= self::DECODED_CAP) {
                self::$decoded = array_slice(self::$decoded, (int) (self::DECODED_CAP / 2), null, true);
            }

            self::$decoded[$k] = (string) DB::table('boundaries')->where('iso3', $row->iso3)->where('level', $row->level)->where('code', $row->code)->value('geometry');
        }

        return self::$decoded[$k];
    }

    /**
     * Codes and names for one country and level - no geometry. Fetched from
     * the source the first time a country is asked for; a country the source
     * does not have is remembered as absent for a day, a fetch that merely
     * failed for fifteen minutes.
     */
    private function names(string $iso3, int $level): array
    {
        $k = "{$iso3}:{$level}";

        if (isset(self::$names[$k])) {
            return self::$names[$k];
        }

        $rows = $this->readNames($iso3, $level);

        if ($rows === [] && $level <= 1 && !Cache::has("boundaries:absent:{$k}")) {
            try {
                $n = $this->loader->load($iso3, $level);

                if ($n === null) {
                    Cache::put("boundaries:absent:{$k}", 1, now()->addDay());
                } else {
                    $rows = $this->readNames($iso3, $level);
                }
            } catch (\Throwable $e) {
                Log::warning('Boundary fetch failed', ['iso3' => $iso3, 'level' => $level, 'error' => $e->getMessage()]);
                Cache::put("boundaries:absent:{$k}", 1, now()->addMinutes(15));
            }
        }

        return self::$names[$k] = $rows;
    }

    private function readNames(string $iso3, int $level): array
    {
        return array_map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'name_key' => $r->name_key, 'parent' => $r->parent_code],
            DB::table('boundaries')->where('iso3', $iso3)->where('level', $level)->orderBy('name')->get(['code', 'name', 'name_key', 'parent_code'])->all());
    }

    /** For tests and reloads. */
    public static function forget(): void
    {
        self::$decoded = [];
        self::$names = [];
    }
}
