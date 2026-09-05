<?php

namespace App\Services\Geo;

use App\Services\Geo\Boundaries\BoundaryCheck;
use Illuminate\Support\Facades\DB;

/**
 * Places that are not IN any gazetteer because they are not places: a
 * point on a road. Computed from the road geometry in our own OpenStreetMap
 * database (the Nominatim container's PostGIS, connection "osm").
 *
 *  - A JUNCTION of two named roads is where their geometries meet.
 *    "junction of Jalan Kota Belud-Kudat and Jalan Berungis" is one point.
 *    A T-junction named with ONE road and a town is the nearest crossing of
 *    that road with another main road, held to the town - approximate.
 *  - A KILOMETRE MARKER is a distance along a route. The route's centreline
 *    is measured from its kilometre zero: from mapped kilometre posts when
 *    the gazetteer holds two or more on that route (they calibrate both
 *    origin and direction), otherwise from the documented origin of the
 *    expressway. A route with neither is refused - a chainage measured from
 *    the wrong end is 400 km wrong with real coordinates.
 */
class RoadGeocoder
{
    /** Expressway names as stories write them -> OSM route ref(s). */
    private const ROUTES = [
        '/\b(?:plus|lebuh\s*raya\s+utara[\s-]*selatan|north[\s-]*south\s+expressway|nse|nkve)\b/iu' => ['E1', 'E2'],
        '/\b(?:lpt\s*1?|lebuh\s*raya\s+pantai\s+timur(?:\s*1)?|east\s+coast\s+expressway(?:\s*1)?|karak\s+highway|lebuhraya\s+karak|klk)\b/iu' => ['E8'],
        '/\b(?:elite)\b/iu' => ['E6'], '/\b(?:kesas)\b/iu' => ['E5'], '/\b(?:ldp)\b/iu' => ['E11'], '/\b(?:mex)\b/iu' => ['E20'],
        '/\b(?:skve)\b/iu' => ['E26'], '/\b(?:lekas)\b/iu' => ['E21'], '/\b(?:silk)\b/iu' => ['E18'], '/\b(?:duke)\b/iu' => ['E33'],
        '/\b(?:sprint)\b/iu' => ['E23'], '/\b(?:guthrie)\b/iu' => ['E35'], '/\b(?:senai[\s-]*desaru)\b/iu' => ['E22'],
        '/\b(?:butterworth[\s-]*kulim|bkE)\b/iu' => ['E15'], '/\b(?:pan\s+borneo)\b/iu' => ['AH150'],
    ];

    /** Documented kilometre-zero of routes whose posts are not mapped. [lat, lng] */
    private const ORIGINS = [
        'E1' => [6.5140, 100.4210],   // Bukit Kayu Hitam, the Thai border
        'E8' => [3.2490, 101.7260],   // Gombak toll plaza
    ];

    private const MAIN_ROADS = ['motorway', 'trunk', 'primary', 'secondary', 'tertiary'];

    /** @var list<array{step:string, query:string, outcome:string}> */
    private array $attempts = [];

    public function attempts(): array
    {
        return $this->attempts;
    }

    /** Does this name describe a point on a road at all? Cheap, no database. */
    public static function looksLikeRoadPoint(string $name): bool
    {
        return preg_match('/\b(?:km|kilomet(?:er|re)s?)\s*\.?\s*\d/iu', $name)
            || preg_match('/\b(?:junction|t-junction|simpang|persimpangan|intersection|crossroads?)\b/iu', $name);
    }

    /**
     * @return ?array{lat:float, lng:float, label:string, display:string, confidence:float, provider:string, precision:string, state:?string, country:?string}
     */
    public function resolve(string $name, ?string $home = 'my'): ?array
    {
        $this->attempts = [];

        $check = new BoundaryCheck();
        $e = $check->expectation($name, $home);

        if (($e['country'] ?? null) === null) {
            return null;
        }

        $bbox = $this->bbox($e);

        if ($bbox === null) {
            $this->note('road', $name, 'no state box to search inside');

            return null;
        }

        $result = $this->km($name, $e, $bbox, $home) ?? $this->junction($name, $e, $bbox, $home);

        if ($result === null) {
            return null;
        }

        $v = $check->verify($name, $result['lat'], $result['lng'], $home);

        if ($v['verdict'] === 'outside') {
            $this->note($result['provider'], $name, 'refused by boundary: ' . $v['detail']);

            return null;
        }

        return $result + ['state' => $e['state_name'] ?? null, 'country' => $e['country']];
    }

    // ── kilometre markers ──────────────────────────────────────────────────

    private function km(string $name, array $e, array $bbox, ?string $home): ?array
    {
        if (!preg_match('/\b(?:km|kilomet(?:er|re)s?)\s*\.?\s*(\d+(?:[.,]\d+)?)/iu', $name, $m)) {
            return null;
        }

        $km = (float) str_replace(',', '.', $m[1]);
        $refs = [];

        foreach (self::ROUTES as $pattern => $r) {
            if (preg_match($pattern, $name)) {
                $refs = $r;
                break;
            }
        }

        if ($refs === [] && preg_match('/\b(?:jalan\s+persekutuan|federal\s+(?:route|road)|ft|laluan\s+persekutuan)\s*(\d{1,4})\b/iu', $name, $f)) {
            $refs = [$f[1]];
        }

        if ($refs === []) {
            // "Km24.5 of the Melawi-Kuala Besut road": the road's own ref.
            foreach ($this->roadsNamed($name) as $road) {
                $row = DB::connection('osm')->selectOne(
                    "select name->'ref' as ref, count(*) n from placex where class = 'highway' and exist(name, 'ref') and name->'name' ilike :r"
                    . sprintf(' and geometry && ST_MakeEnvelope(%F, %F, %F, %F, 4326)', $bbox[2], $bbox[0], $bbox[3], $bbox[1])
                    . " group by 1 order by 2 desc limit 1", ['r' => $this->like($road)]);

                if ($row !== null && $row->ref !== null) {
                    $refs = [strtoupper(explode(';', $row->ref)[0])];
                    $this->note('road:km', $name, sprintf('"%s" is route %s in the road data', $road, $refs[0]));
                    break;
                }
            }
        }

        if ($refs === []) {
            $this->note('road:km', $name, 'a kilometre on a road whose route number neither the text nor the road data gives');

            return null;
        }

        $anchor = $this->anchor($name, $e, $home, $bbox);
        $best = null;

        foreach ($refs as $ref) {
            $p = $this->pointAlongRoute($ref, $km, $bbox);

            if ($p === null) {
                continue;
            }

            $dist = $anchor === null ? null : $this->kmBetween($p['lat'], $p['lng'], $anchor['lat'], $anchor['lng']);

            if ($dist !== null && $dist > 40.0) {
                // One route number, measured along its chain, and the point
                // is in the state the story claims: the writer's "near
                // Kuantan" for KM 110 of the LPT (103 km west of Kuantan) is
                // loose, the chainage is not. Two candidate routes: the town
                // still decides between them.
                $inState = ($e['state'] ?? null) === null
                    || (new \App\Services\Geo\Boundaries\BoundaryCheck())->verify($name, $p['lat'], $p['lng'], $home)['verdict'] !== 'outside';

                if (count($refs) === 1 && $inState) {
                    $this->note('road:km', "{$ref} KM {$km}", sprintf('computed at %.5f, %.5f; %.0f km from %s, but the route is not in doubt and the point is in the claimed state', $p['lat'], $p['lng'], $dist, $anchor['name']));
                    $p['note'] = sprintf('KM %s of %s, measured along the road; the story says near %s, which is %.0f km away', $km, $ref, $anchor['name'], $dist);
                    $dist = null;
                } else {
                    $this->note('road:km', "{$ref} KM {$km}", sprintf('computed at %.5f, %.5f but %.0f km from %s - not this route', $p['lat'], $p['lng'], $dist, $anchor['name']));
                    continue;
                }
            }

            if ($best === null || ($dist !== null && $dist < $best['dist'])) {
                $best = $p + ['dist' => $dist, 'ref' => $ref];
            }
        }

        if ($best === null) {
            return null;
        }

        $this->note('road:km', "{$best['ref']} KM {$km}", sprintf('%.5f, %.5f (%s)%s', $best['lat'], $best['lng'], $best['how'],
            $best['dist'] !== null ? sprintf(', %.0f km from %s', $best['dist'], $anchor['name']) : ''));

        return ['lat' => $best['lat'], 'lng' => $best['lng'], 'label' => "KM {$km} {$best['ref']}", 'display' => "KM {$km}, route {$best['ref']} ({$best['how']})",
            'confidence' => $best['calibrated'] ? 0.75 : 0.55, 'provider' => 'road:km', 'precision' => 'approximate_area'];
    }

    /**
     * The point KM n along route $ref.
     *
     * The route's ways are merged into segments (a dual carriageway and the
     * gaps at interchanges leave several). The segments are ordered from the
     * route's kilometre zero and the chainage is ROAD distance accumulated
     * along them - each segment's length plus the gap to the next. Straight-
     * line "gap allowance" put KM 110 of the LPT 24 km east of the Temerloh
     * toll it is next to; this puts it beside it. Kilometre posts on the
     * route, when mapped, calibrate the result and override the origin.
     *
     * @return ?array{lat:float, lng:float, how:string, calibrated:bool}
     */
    private function pointAlongRoute(string $ref, float $km, array $bbox): ?array
    {
        $segments = DB::connection('osm')->select(<<<'SQL'
            with w as (
                select geometry from placex
                where class = 'highway' and type in ('motorway', 'trunk', 'primary')
                  and :ref = any(string_to_array(replace(name->'ref', ' ', ''), ';'))
            ), m as (
                select (ST_Dump(ST_LineMerge(ST_Union(geometry)))).geom as l from w
            )
            select ST_AsText(l) as wkt, ST_Length(l::geography) as len,
                   ST_Y(ST_StartPoint(l)) as slat, ST_X(ST_StartPoint(l)) as slng, ST_Y(ST_EndPoint(l)) as elat, ST_X(ST_EndPoint(l)) as elng
            from m where ST_Length(l::geography) > 500 order by len desc
            SQL, ['ref' => $ref]);

        if ($segments === []) {
            $this->note('road:km', "{$ref} KM {$km}", "no route {$ref} in the road data");

            return null;
        }

        // Calibration first: posts on this route, projected onto the segment they sit on.
        $posts = DB::table('gazetteer')->where('source', 'milestone')->where('category', $ref)->whereNotNull('population')->get(['population as km', 'lat', 'lng']);
        $fixes = [];   // [km => [segment index, fraction]]

        foreach ($posts as $post) {
            foreach ($segments as $i => $seg) {
                $r = DB::connection('osm')->selectOne('select ST_LineLocatePoint(ST_GeomFromText(:wkt, 4326), ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)) as f, ST_Distance(ST_GeomFromText(:wkt2, 4326)::geography, ST_SetSRID(ST_MakePoint(:lng2, :lat2), 4326)::geography) as d',
                    ['wkt' => $seg->wkt, 'lng' => $post->lng, 'lat' => $post->lat, 'wkt2' => $seg->wkt, 'lng2' => $post->lng, 'lat2' => $post->lat]);

                if ((float) $r->d < 200) {
                    $fixes[(int) $post->km] = ['i' => $i, 'f' => (float) $r->f];
                    break;
                }
            }
        }

        // Two posts on the SAME segment bracketing (or nearest to) the asked km: interpolate between them.
        $bySeg = [];
        foreach ($fixes as $k => $fx) { $bySeg[$fx['i']][$k] = $fx['f']; }

        foreach ($bySeg as $i => $ks) {
            if (count($ks) < 2) continue;
            ksort($ks);
            $keys = array_keys($ks);
            $lo = null; $hi = null;
            foreach ($keys as $k) { if ($k <= $km) $lo = $k; if ($k >= $km && $hi === null) $hi = $k; }
            if ($lo === null || $hi === null) { [$lo, $hi] = abs($keys[0] - $km) < abs(end($keys) - $km) ? [$keys[0], $keys[1]] : [$keys[count($keys) - 2], end($keys)]; }
            if ($lo === $hi) continue;
            $f = $ks[$lo] + ($km - $lo) * ($ks[$hi] - $ks[$lo]) / ($hi - $lo);
            if ($f < -0.05 || $f > 1.05) continue;
            $f = max(0.0, min(1.0, $f));
            $pt = DB::connection('osm')->selectOne('select ST_Y(p) lat, ST_X(p) lng from (select ST_LineInterpolatePoint(ST_GeomFromText(:wkt, 4326), :f) p) x', ['wkt' => $segments[$i]->wkt, 'f' => $f]);

            return ['lat' => (float) $pt->lat, 'lng' => (float) $pt->lng, 'how' => "between posts KM {$lo} and KM {$hi}", 'calibrated' => true];
        }

        if (!isset(self::ORIGINS[$ref])) {
            $this->note('road:km', "{$ref} KM {$km}", 'no usable kilometre posts on this route and no documented origin - refusing to guess the end');

            return null;
        }

        // Order the segments from the origin: repeatedly take the segment whose
        // nearer end is closest to where the chain currently ends.
        [$olat, $olng] = self::ORIGINS[$ref];
        $chain = [];
        $cur = ['lat' => $olat, 'lng' => $olng];
        $remaining = $segments;
        $walked = 0.0;   // metres of road from the origin to the start of the current segment

        while ($remaining !== []) {
            $bestI = null; $bestD = INF; $flip = false;
            foreach ($remaining as $i => $seg) {
                $ds = $this->kmBetween($cur['lat'], $cur['lng'], (float) $seg->slat, (float) $seg->slng);
                $de = $this->kmBetween($cur['lat'], $cur['lng'], (float) $seg->elat, (float) $seg->elng);
                if (min($ds, $de) < $bestD) { $bestD = min($ds, $de); $bestI = $i; $flip = $de < $ds; }
            }
            $seg = $remaining[$bestI];
            unset($remaining[$bestI]);
            $gap = $bestD * 1000;
            $chain[] = ['seg' => $seg, 'flip' => $flip, 'from' => $walked + $gap, 'to' => $walked + $gap + (float) $seg->len];
            $walked += $gap + (float) $seg->len;
            $cur = $flip ? ['lat' => (float) $seg->slat, 'lng' => (float) $seg->slng] : ['lat' => (float) $seg->elat, 'lng' => (float) $seg->elng];
            // a parallel carriageway begins near where this one began: skip segments that would walk us backwards
            foreach ($remaining as $j => $other) {
                $near = min($this->kmBetween($cur['lat'], $cur['lng'], (float) $other->slat, (float) $other->slng), $this->kmBetween($cur['lat'], $cur['lng'], (float) $other->elat, (float) $other->elng));
                $overlapsStart = min($this->kmBetween((float) $seg->slat, (float) $seg->slng, (float) $other->slat, (float) $other->slng), $this->kmBetween((float) $seg->slat, (float) $seg->slng, (float) $other->elat, (float) $other->elng));
                if ($overlapsStart < 1.0 && $near < 1.0 && abs((float) $other->len - (float) $seg->len) / max(1.0, (float) $seg->len) < 0.3) {
                    unset($remaining[$j]);   // the other carriageway of the segment just walked
                }
            }
        }

        $this->note('road:km', "{$ref} chain", implode(' -> ', array_map(fn ($l) => sprintf('%.0f-%.0f km%s', $l['from'] / 1000, $l['to'] / 1000, $l['flip'] ? ' (reversed)' : ''), $chain)));

        $target = $km * 1000;
        foreach ($chain as $link) {
            if ($target >= $link['from'] && $target <= $link['to']) {
                $f = ((float) $link['seg']->len) > 0 ? ($target - $link['from']) / (float) $link['seg']->len : 0.0;
                $f = $link['flip'] ? 1 - $f : $f;
                $pt = DB::connection('osm')->selectOne('select ST_Y(p) lat, ST_X(p) lng from (select ST_LineInterpolatePoint(ST_GeomFromText(:wkt, 4326), :f) p) x', ['wkt' => $link['seg']->wkt, 'f' => max(0.0, min(1.0, $f))]);

                return ['lat' => (float) $pt->lat, 'lng' => (float) $pt->lng, 'how' => sprintf('road distance from the route origin over %d segment(s)', count($chain)), 'calibrated' => false];
            }
        }

        $this->note('road:km', "{$ref} KM {$km}", sprintf('KM %s is beyond the %.0f km of route mapped from the origin', $km, $walked / 1000));

        return null;
    }

    // ── junctions ──────────────────────────────────────────────────────────

    private function junction(string $name, array $e, array $bbox, ?string $home): ?array
    {
        if (!preg_match('/\b(?:junction|t-junction|simpang|persimpangan|intersection|crossroads?)\b/iu', $name)) {
            return null;
        }

        $roads = $this->roadsNamed($name);

        if ($roads === []) {
            $this->note('road:junction', $name, 'no road name in the text to intersect');

            return null;
        }

        $env = sprintf('ST_MakeEnvelope(%F, %F, %F, %F, 4326)', $bbox[2], $bbox[0], $bbox[3], $bbox[1]);
        $anchor = $this->anchor($name, $e, $home, $bbox);

        if (count($roads) >= 2) {
            $rows = DB::connection('osm')->select(<<<SQL
                with a as (select ST_Union(geometry) g from placex where class = 'highway' and {$this->likeAny($roads[0])} and geometry && {$env}),
                     b as (select ST_Union(geometry) g from placex where class = 'highway' and {$this->likeAny($roads[1])} and geometry && {$env})
                select ST_Y(p) lat, ST_X(p) lng from (
                    select (ST_Dump(ST_Intersection(a.g, b.g))).geom p from a, b
                    union all
                    select ST_ClosestPoint(a.g, b.g) p from a, b
                    where a.g is not null and b.g is not null and not ST_Intersects(a.g, b.g) and ST_DWithin(a.g::geography, b.g::geography, 60)
                ) d
                where ST_GeometryType(p) = 'ST_Point'
                SQL);

            if ($rows === []) {
                $this->note('road:junction', $name, sprintf('"%s" and "%s" do not meet inside the state (or one is not in the road data)', $roads[0], $roads[1]));
            } else {
                $p = $this->nearestTo($rows, $anchor);
                $this->note('road:junction', $name, sprintf('%s meets %s at %.5f, %.5f (%d crossing%s)', $roads[0], $roads[1], $p->lat, $p->lng, count($rows), count($rows) === 1 ? '' : 's'));

                return ['lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'label' => "Junction of {$roads[0]} and {$roads[1]}", 'display' => "Junction of {$roads[0]} and {$roads[1]}",
                    'confidence' => count($rows) === 1 ? 0.85 : 0.65, 'provider' => 'road:junction', 'precision' => count($rows) === 1 ? 'exact_area' : 'approximate_area'];
            }
        }

        // One road: its nearest crossing with another main road, held to the town.
        if ($anchor === null) {
            $this->note('road:junction', $name, 'one road and no town to hold the junction to');

            return null;
        }

        $roadSql = $this->likeAny($roads[0]);

        // "Kota Belud-Kundasang road" is "Jalan Kota Belud - Ranau" on the map:
        // when the two-town name matches nothing, take the road named after
        // the first town whose far end lies nearest the second town.
        $towns = preg_split('/\s*[-\x{2013}\x{2014}\/]\s*/u', preg_replace('/^(jalan|jln)\s+/iu', '', $roads[0]));

        if (count($towns) === 2) {
            $exists = DB::connection('osm')->selectOne("select count(*) n from placex where class = 'highway' and {$roadSql} and geometry && {$env}");

            if ((int) $exists->n === 0) {
                $far = DB::connection('osm')->selectOne("select ST_Y(centroid) lat, ST_X(centroid) lng from placex where class = 'place' and type in ('city', 'town', 'village', 'suburb', 'hamlet') and lower(name->'name') = lower(:t) and geometry && {$env} order by rank_search limit 1", ['t' => $towns[1]]);
                $q = fn ($s) => "'" . str_replace("'", "''", '%' . preg_replace('/\s+/', '%', $s) . '%') . "'";

                if ($far !== null) {
                    $alt = DB::connection('osm')->selectOne("select name->'name' as n, min(ST_Distance(geometry::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography)) / 1000 as km
                        from placex where class = 'highway' and type in ('trunk', 'primary', 'secondary') and name->'name' ilike {$q($towns[0])} and geometry && {$env}
                        group by 1 order by 2 limit 1", ['lng' => $far->lng, 'lat' => $far->lat]);

                    if ($alt !== null && (float) $alt->km < 25) {
                        $this->note('road:junction', $roads[0], sprintf('not a road name on the map; taking "%s", the %s road that runs nearest %s (%.0f km)', $alt->n, $towns[0], $towns[1], $alt->km));
                        $roadSql = "name->'name' = " . "'" . str_replace("'", "''", $alt->n) . "'";
                        $roads[0] = $alt->n;
                    }
                }
            }
        }

        $rows = DB::connection('osm')->select(<<<SQL
            with r as (select ST_Union(geometry) g from placex where class = 'highway' and {$roadSql} and geometry && {$env})
            select o.name->'name' as other, ST_Y(x.pt) lat, ST_X(x.pt) lng,
                   ST_Distance(x.pt::geography, ST_SetSRID(ST_MakePoint(:alng, :alat), 4326)::geography) / 1000 as km
            from r, placex o, lateral (select (ST_Dump(ST_Intersection(r.g, o.geometry))).geom pt) x
            where o.class = 'highway' and o.type in ('trunk', 'primary', 'secondary', 'tertiary') and o.geometry && {$env}
              and exist(o.name, 'name') and not ({$this->likeAny($roads[0], "o.name->'name'")}) and ST_GeometryType(x.pt) = 'ST_Point'
              and ST_DWithin(x.pt::geography, ST_SetSRID(ST_MakePoint(:alng2, :alat2), 4326)::geography, 15000)
            order by km limit 3
            SQL, ['alng' => $anchor['lng'], 'alat' => $anchor['lat'], 'alng2' => $anchor['lng'], 'alat2' => $anchor['lat']]);

        if ($rows === []) {
            $this->note('road:junction', $name, sprintf('"%s" crosses no other main road within 15 km of %s', $roads[0], $anchor['name']));

            return null;
        }

        $p = $rows[0];
        $this->note('road:junction', $name, sprintf('%s crosses %s at %.5f, %.5f, %.1f km from %s (nearest of %d)', $roads[0], $p->other, $p->lat, $p->lng, $p->km, $anchor['name'], count($rows)));

        return ['lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'label' => "Junction of {$roads[0]} and {$p->other}", 'display' => "Junction of {$roads[0]} and {$p->other}, near {$anchor['name']}",
            'confidence' => 0.5, 'provider' => 'road:junction', 'precision' => 'approximate_area'];
    }

    /** Road names in the text: "Jalan X", "X road", "X-Y road", "junction of A and B". */
    private function roadsNamed(string $name): array
    {
        $text = preg_replace('/\s*\([^)]*\)/', '', $name);
        $out = [];

        if (preg_match('/(?:junction|intersection|persimpangan|simpang)\s+(?:of\s+|antara\s+)?(.+?)\s+(?:and|dan|with|\/|&)\s+(.+?)(?:,|$)/iu', $text, $m)) {
            $out[] = trim($m[1]);
            $out[] = trim($m[2]);
        }

        foreach (preg_split('/\s*,\s*/', $text) as $part) {
            if (preg_match('/\b((?:jalan|jln|lebuh|lebuhraya|persiaran|lorong)\s+[\p{L}\d][\p{L}\d\s\'\-\.\/]*?)(?:\s+(?:t-junction|junction|simpang|persimpangan))?$/iu', trim($part), $m)) {
                $out[] = trim($m[1]);
            } elseif (preg_match('/^(.+?)\s+(?:road|highway|expressway|trunk\s+road)(?:\s+t-junction|\s+junction)?$/iu', trim($part), $m)) {
                $out[] = trim($m[1]);   // "Kota Belud-Kundasang road" -> "Kota Belud-Kundasang"
            }
        }

        $out = array_values(array_unique(array_filter($out, fn ($r) => mb_strlen($r) >= 4)));

        return array_slice($out, 0, 2);
    }

    /** "Kota Belud-Kundasang" matches "Jalan Kota Belud - Kundasang"; the reversed form is tried by likeAny(). */
    private function like(string $road): string
    {
        $r = preg_replace('/^(jalan|jln|lebuh|lebuhraya)\s+/iu', '', trim($road));
        $r = preg_replace('/\s*[-\x{2013}\x{2014}\/]\s*/u', '%', $r);
        $r = preg_replace('/\s+/', '%', $r);

        return '%' . $r . '%';
    }

    /** Both orders of a two-town road name, as a SQL fragment on name->'name'. */
    private function likeAny(string $road, string $col = "name->'name'"): string
    {
        $r = preg_replace('/^(jalan|jln|lebuh|lebuhraya)\s+/iu', '', trim($road));
        $towns = preg_split('/\s*[-\x{2013}\x{2014}\/]\s*/u', $r);
        $q = fn ($s) => "'" . str_replace("'", "''", '%' . preg_replace('/\s+/', '%', $s) . '%') . "'";

        if (count($towns) === 2) {
            return "({$col} ilike {$q($towns[0] . ' ' . $towns[1])} or {$col} ilike {$q($towns[1] . ' ' . $towns[0])})";
        }

        return "{$col} ilike {$q($r)}";
    }

    // ── shared ─────────────────────────────────────────────────────────────

    /**
     * The town the name gives, as a point inside the claimed state's box -
     * "Tapah" and "Kepala Batas" both exist in more than one state. The
     * parts after the place first, narrowest first; then "near X" in the
     * text; then the towns a road is named after ("Kota Belud-Kundasang").
     */
    private function anchor(string $name, array $e, ?string $home, array $bbox = []): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', preg_replace('/\s*\([^)]*\)/', '', $name)))));
        $stateName = mb_strtolower((string) ($e['state_name'] ?? ''));
        $candidates = [];

        foreach (array_slice($parts, 1) as $part) {
            $town = preg_replace('/^(?:near|berhampiran|dekat)\s+/iu', '', $part);

            if (mb_strtolower($town) === $stateName || PlaceScale::isTooBigToBeNear($town) || preg_match('/\b(road|jalan|highway|lebuhraya|expressway|km|kilomet)/iu', $town)) {
                continue;
            }

            $candidates[] = $town;
        }

        if (preg_match('/\b(?:near|berhampiran)\s+([\p{L}\s]+?)(?:,|$)/iu', $name, $m)) {
            $candidates[] = trim($m[1]);
        }

        foreach ($this->roadsNamed($name) as $road) {
            foreach (preg_split('/\s*[-\x{2013}\x{2014}\/]\s*/u', preg_replace('/^(jalan|jln)\s+/iu', '', $road)) as $town) {
                if (mb_strlen(trim($town)) >= 4) {
                    $candidates[] = trim($town);
                }
            }
        }

        foreach ($candidates as $town) {
            $row = DB::connection('osm')->selectOne(<<<'SQL'
                select ST_Y(centroid) lat, ST_X(centroid) lng, name->'name' as n from placex
                where class = 'place' and type in ('city', 'town', 'village', 'suburb', 'municipality', 'hamlet')
                  and lower(name->'name') = lower(:t) and country_code = :cc
                  and ST_Y(centroid) between :s and :n and ST_X(centroid) between :w and :e
                order by rank_search limit 1
                SQL, ['t' => $town, 'cc' => strtolower($home ?? 'my'), 's' => $bbox[0] ?? -90, 'n' => $bbox[1] ?? 90, 'w' => $bbox[2] ?? -180, 'e' => $bbox[3] ?? 180]);

            if ($row !== null) {
                return ['name' => $row->n, 'lat' => (float) $row->lat, 'lng' => (float) $row->lng];
            }
        }

        return null;
    }

    private function nearestTo(array $rows, ?array $anchor): object
    {
        if ($anchor === null || count($rows) === 1) {
            return $rows[0];
        }

        usort($rows, fn ($a, $b) => $this->kmBetween((float) $a->lat, (float) $a->lng, $anchor['lat'], $anchor['lng']) <=> $this->kmBetween((float) $b->lat, (float) $b->lng, $anchor['lat'], $anchor['lng']));

        return $rows[0];
    }

    /** @return ?float[] [south, north, west, east] of the claimed state, else the country */
    private function bbox(array $e): ?array
    {
        $q = DB::table('boundaries')->where('iso3', $e['country']);
        $row = ($e['state'] ?? null) !== null ? $q->where('level', 1)->where('code', $e['state'])->first() : $q->where('level', 0)->first();

        return $row ? [(float) $row->min_lat, (float) $row->max_lat, (float) $row->min_lng, (float) $row->max_lng] : null;
    }

    private function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function note(string $step, string $query, string $outcome): void
    {
        $this->attempts[] = ['step' => $step, 'query' => mb_substr($query, 0, 120), 'outcome' => mb_substr($outcome, 0, 200)];
    }
}
