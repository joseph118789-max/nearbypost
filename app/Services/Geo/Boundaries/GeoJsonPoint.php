<?php

namespace App\Services\Geo\Boundaries;

/**
 * Is a point inside a GeoJSON polygon? Ray casting, holes respected.
 *
 * No PostGIS on this box, and none needed: a boundary check is one point
 * against a few hundred polygons of a few thousand vertices each, and PHP
 * does that in well under a millisecond once the bounding box has done its
 * work. GeoJSON is [longitude, latitude]; everything else in this codebase is
 * (lat, lng). The swap happens here and nowhere else.
 */
class GeoJsonPoint
{
    /** @param array $geometry a GeoJSON Polygon or MultiPolygon */
    public static function inside(float $lat, float $lng, array $geometry): bool
    {
        $type = $geometry['type'] ?? '';

        if ($type === 'Polygon') {
            return self::inPolygon($lat, $lng, $geometry['coordinates']);
        }

        if ($type === 'MultiPolygon') {
            foreach ($geometry['coordinates'] as $polygon) {
                if (self::inPolygon($lat, $lng, $polygon)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Outer ring, minus any holes. */
    private static function inPolygon(float $lat, float $lng, array $rings): bool
    {
        if ($rings === [] || !self::inRing($lat, $lng, $rings[0])) {
            return false;
        }

        for ($i = 1, $n = count($rings); $i < $n; $i++) {
            if (self::inRing($lat, $lng, $rings[$i])) {
                return false;   // in a hole
            }
        }

        return true;
    }

    /** The classic even-odd test. */
    private static function inRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $n = count($ring);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $ring[$i][0]; $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0]; $yj = (float) $ring[$j][1];

            if ((($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Point-in-polygon over the raw GeoJSON TEXT, never building the arrays.
     *
     * json_decode turns a 104,000-vertex outline (Indonesia, simplified) into
     * about a hundred megabytes of nested PHP arrays, and Indonesia's box
     * covers every point on Borneo - so a Sabah story asked "which country"
     * decoded Indonesia and died at php-fpm's limit. This walks the text
     * instead: brackets give the ring structure, numbers arrive in pairs, and
     * the even-odd test needs only the previous vertex and the first. Memory
     * is a few variables whatever the polygon's size.
     *
     * GeoJSON nesting: Polygon = [ring, ring...]; MultiPolygon = [[ring...],
     * ...]. A ring is a run of [lng, lat] pairs. The first ring of a polygon
     * is its outer edge; the rest are holes.
     */
    public static function insideJson(float $lat, float $lng, string $json): bool
    {
        $multi = str_contains($json, '"MultiPolygon"');
        $ringDepth = $multi ? 3 : 2;           // depth at which a ring's pairs sit

        $pos = strpos($json, '"coordinates"');
        if ($pos === false) {
            return false;
        }

        $len = strlen($json);
        $depth = 0;
        $polygonInside = false;   // result for the polygon being read
        $ringIndex = -1;          // which ring of the current polygon
        $anyInside = false;

        // per-ring state
        $ringInside = false; $firstX = $firstY = null; $prevX = $prevY = null;
        $num = ''; $pairX = null;

        $finishRing = function () use (&$ringInside, &$firstX, &$firstY, &$prevX, &$prevY, $lat, $lng) {
            // close the ring: edge from the last vertex back to the first
            if ($firstX !== null && $prevX !== null && ($prevX !== $firstX || $prevY !== $firstY)) {
                if ((($prevY > $lat) !== ($firstY > $lat))
                    && ($lng < ($firstX - $prevX) * ($lat - $prevY) / (($firstY - $prevY) ?: 1e-12) + $prevX)) {
                    $ringInside = !$ringInside;
                }
            }
        };

        for ($i = $pos; $i < $len; $i++) {
            $c = $json[$i];

            if ($c === '[') {
                $depth++;
                if ($depth === $ringDepth) {           // a ring begins
                    $ringIndex++;
                    $ringInside = false; $firstX = $firstY = $prevX = $prevY = null;
                }
                if ($depth === $ringDepth - 1) {       // a polygon begins
                    $ringIndex = -1; $polygonInside = false;
                }
                continue;
            }

            if ($c === ']') {
                if ($depth === $ringDepth + 1 && $num !== '') {   // closing a pair
                    $y = (float) $num; $num = '';
                    $x = $pairX; $pairX = null;
                    self::step($x, $y, $lat, $lng, $ringInside, $firstX, $firstY, $prevX, $prevY);
                }
                if ($depth === $ringDepth) {           // a ring ends
                    $finishRing();
                    if ($ringIndex === 0) {
                        $polygonInside = $ringInside;  // outer edge
                    } elseif ($ringInside) {
                        $polygonInside = false;        // in a hole
                    }
                }
                if ($depth === $ringDepth - 1) {       // a polygon ends
                    if ($polygonInside) {
                        $anyInside = true;
                        if ($multi) { return true; }
                    }
                }
                $depth--;
                if ($depth === 0) { break; }
                continue;
            }

            if ($depth === $ringDepth + 1) {
                if ($c === ',') {
                    if ($num !== '') { $pairX = (float) $num; $num = ''; }
                } elseif ($c !== ' ' && $c !== "\n" && $c !== "\r" && $c !== "\t") {
                    $num .= $c;
                }
            }
        }

        return $anyInside;
    }

    /** One vertex of a ring, fed to the even-odd test against the previous one. */
    private static function step(float $x, float $y, float $lat, float $lng, bool &$inside, ?float &$fx, ?float &$fy, ?float &$px, ?float &$py): void
    {
        if ($fx === null) {
            $fx = $x; $fy = $y;
        } elseif ((($y > $lat) !== ($py > $lat))
            && ($lng < ($px - $x) * ($lat - $y) / (($py - $y) ?: 1e-12) + $x)) {
            $inside = !$inside;
        }

        $px = $x; $py = $y;
    }

    /** distanceToEdgeKm over the JSON text: nearest vertex within $withinKm, or null. */
    public static function distanceToEdgeKmJson(float $lat, float $lng, string $json, float $withinKm): ?float
    {
        $pos = strpos($json, '"coordinates"');
        if ($pos === false) {
            return null;
        }

        $best = null;
        $cosLat = cos(deg2rad($lat));
        $len = strlen($json);
        $num = ''; $x = null; $inPair = false;

        for ($i = $pos; $i < $len; $i++) {
            $c = $json[$i];

            if ($c === '[' ) { $inPair = true; $num = ''; $x = null; continue; }
            if ($c === ',') {
                if ($inPair && $num !== '' && $x === null) { $x = (float) $num; $num = ''; }
                continue;
            }
            if ($c === ']') {
                if ($inPair && $num !== '' && $x !== null) {
                    $y = (float) $num;
                    $dLat = ($y - $lat) * 111.32;
                    $dLng = ($x - $lng) * 111.32 * $cosLat;
                    $d = sqrt($dLat * $dLat + $dLng * $dLng);
                    if ($d <= $withinKm && ($best === null || $d < $best)) { $best = $d; }
                }
                $inPair = false; $num = ''; $x = null;
                continue;
            }
            if ($inPair && $c !== ' ' && $c !== "\n" && $c !== "\r" && $c !== "\t") {
                $num .= $c;
            }
        }

        return $best;
    }

    /**
     * Distance from a point to the nearest vertex of a geometry, in km, or
     * null if no vertex is within $withinKm. Vertex distance, not true edge
     * distance: at the resolutions used here the difference is under the
     * tolerance it is compared against, and it needs no trigonometry per
     * segment.
     */
    public static function distanceToEdgeKm(float $lat, float $lng, array $geometry, float $withinKm): ?float
    {
        $best   = null;
        $cosLat = cos(deg2rad($lat));

        foreach (self::points($geometry) as [$x, $y]) {
            // Equirectangular: exact enough over a few kilometres, and cheap
            // enough to run over a hundred thousand vertices.
            $dLat = ($y - $lat) * 111.32;
            $dLng = ($x - $lng) * 111.32 * $cosLat;
            $d    = sqrt($dLat * $dLat + $dLng * $dLng);

            if ($d <= $withinKm && ($best === null || $d < $best)) {
                $best = $d;
            }
        }

        return $best;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} minLat, maxLat, minLng, maxLng */
    public static function bbox(array $geometry): array
    {
        $minLat = 90.0; $maxLat = -90.0; $minLng = 180.0; $maxLng = -180.0;

        foreach (self::points($geometry) as [$lng, $lat]) {
            if ($lat < $minLat) $minLat = $lat;
            if ($lat > $maxLat) $maxLat = $lat;
            if ($lng < $minLng) $minLng = $lng;
            if ($lng > $maxLng) $maxLng = $lng;
        }

        return [$minLat, $maxLat, $minLng, $maxLng];
    }

    public static function vertexCount(array $geometry): int
    {
        $n = 0;
        foreach (self::points($geometry) as $_) $n++;

        return $n;
    }

    /** @return \Generator<array{0: float, 1: float}> */
    private static function points(array $geometry): \Generator
    {
        $type = $geometry['type'] ?? '';
        $polygons = $type === 'Polygon' ? [$geometry['coordinates']] : ($type === 'MultiPolygon' ? $geometry['coordinates'] : []);

        foreach ($polygons as $rings) {
            foreach ($rings as $ring) {
                foreach ($ring as $pt) {
                    yield [(float) $pt[0], (float) $pt[1]];
                }
            }
        }
    }
}
