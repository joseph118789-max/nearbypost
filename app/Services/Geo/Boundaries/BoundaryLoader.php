<?php

namespace App\Services\Geo\Boundaries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a country's boundaries from geoBoundaries and stores them.
 *
 * One country at a time, on demand. The table does not need the world loaded
 * before it is useful; it needs Malaysia, and then whichever country the next
 * story names. Loading is idempotent - a second call for the same country and
 * level replaces the rows with the same source release, which is the way to
 * take a boundary update.
 *
 * Simplified geometries by default. The full Malaysian state set is 98,000
 * vertices; the simplified one is 18,000 and differs by tens of metres along
 * a coastline. A story is never placed to tens of metres; the extra vertices
 * would only make every check slower.
 */
class BoundaryLoader
{
    private const API = 'https://www.geoboundaries.org/api/current/gbOpen';
    private const UA  = 'Nearbypost/1.0 (contact@nearbypost.com)';

    /**
     * Load one level for one country. Returns the number of areas stored, or
     * null when the source has nothing at that level (Singapore has no level
     * 2, for instance - that is an answer, not an error).
     */
    public function load(string $iso3, int $level, bool $full = false): ?int
    {
        $iso3 = strtoupper($iso3);

        $meta = Http::withHeaders(['User-Agent' => self::UA])
            ->timeout(60)
            ->get(self::API . "/{$iso3}/ADM{$level}/");

        if ($meta->status() === 404) {
            return null;
        }

        if (!$meta->successful()) {
            throw new \RuntimeException("geoBoundaries {$iso3} ADM{$level}: HTTP " . $meta->status());
        }

        // Simplified for the world; full for the country the site is about.
        // Simplification trims a coastline by tens of metres, and the places
        // that matter most here - a waterfront convention centre, a beach, a
        // port - sit exactly on the trimmed edge.
        $url = $full
            ? ($meta->json('gjDownloadURL') ?: $meta->json('simplifiedGeometryGeoJSON'))
            : ($meta->json('simplifiedGeometryGeoJSON') ?: $meta->json('gjDownloadURL'));

        if (!$url) {
            throw new \RuntimeException("geoBoundaries {$iso3} ADM{$level}: no download URL");
        }

        $body = Http::withHeaders(['User-Agent' => self::UA])->timeout(180)->get($url);

        if (!$body->successful()) {
            throw new \RuntimeException("geoBoundaries {$iso3} ADM{$level} geometry: HTTP " . $body->status());
        }

        $geo = json_decode($body->body(), true);

        if (!is_array($geo) || empty($geo['features'])) {
            throw new \RuntimeException("geoBoundaries {$iso3} ADM{$level}: unreadable GeoJSON");
        }

        // The release is the git commit in the download path; it is the one
        // thing that says which version of the world these rows are.
        $release = preg_match('#/raw/([0-9a-f]{7,})/#', (string) $url, $m) ? $m[1] : null;
        $licence = (string) ($meta->json('licenseDetail') ?? '');
        $iso2    = Iso3166::iso2($iso3);
        $stored  = 0;

        // Level 2 areas must know which state they sit in. The source does
        // not say, so it is worked out: the state polygon containing a point
        // inside the area. The bbox centre first; if that is outside every
        // state (a crescent-shaped district), a vertex of the area itself.
        $states = $level === 2
            ? DB::table('boundaries')->where('iso3', $iso3)->where('level', 1)->get()
                ->map(fn ($r) => ['code' => $r->code, 'min_lat' => (float) $r->min_lat, 'max_lat' => (float) $r->max_lat,
                                  'min_lng' => (float) $r->min_lng, 'max_lng' => (float) $r->max_lng, 'geometry' => json_decode($r->geometry, true)])->all()
            : [];

        if ($level === 2 && $states === []) {
            throw new \RuntimeException("{$iso3}: load level 1 before level 2");
        }

        DB::transaction(function () use ($geo, $iso3, $iso2, $level, $release, $licence, $full, $states, &$stored) {
            DB::table('boundaries')->where('iso3', $iso3)->where('level', $level)->delete();

            $used = [];

            foreach ($geo['features'] as $f) {
                $props = $f['properties'] ?? [];
                $geom  = $f['geometry'] ?? null;

                if (!is_array($geom) || !in_array($geom['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
                    continue;
                }

                $name = trim((string) ($props['shapeName'] ?? ''));
                $name = self::RENAMES[$iso3][$level][$name] ?? $name;

                // Level 0 is coded by its ISO3. Level 1 by ISO 3166-2 when the
                // source has it ("MY-10"); when it does not, by the source's
                // own stable id, so the row still has a key that survives a
                // reload.
                $code = $level === 0
                    ? $iso3
                    : (string) (($props['shapeISO'] ?? '') !== '' && ($props['shapeISO'] ?? '') !== 'None'
                        ? $props['shapeISO']
                        : ($props['shapeID'] ?? ($iso3 . '-' . $stored)));

                // The source gives one Chinese province the country's own
                // code, and some countries repeat a code. A code that is the
                // country's, or already taken in this load, is not a code:
                // the source's stable id stands in, and the load completes
                // instead of dying on a unique key half way through.
                if ($level > 0 && ($code === $iso3 || isset($used[$code]))) {
                    $code = (string) ($props['shapeID'] ?? ($iso3 . '-' . $level . '-' . $stored));
                }

                $used[$code] = true;

                [$minLat, $maxLat, $minLng, $maxLng] = GeoJsonPoint::bbox($geom);

                $parent = $level === 0 ? null : ($level === 1 ? $iso3 : $this->stateContaining($geom, $states, ($minLat + $maxLat) / 2, ($minLng + $maxLng) / 2));

                DB::table('boundaries')->insert([
                    'iso3'           => $iso3,
                    'iso2'           => $iso2,
                    'level'          => $level,
                    'code'           => mb_substr($code, 0, 40),
                    'parent_code'    => $parent,
                    'name'           => mb_substr($name !== '' ? $name : $code, 0, 160),
                    'name_key'       => self::key($name !== '' ? $name : $code),
                    'geometry'       => json_encode($geom),
                    'min_lat'        => $minLat, 'max_lat' => $maxLat,
                    'min_lng'        => $minLng, 'max_lng' => $maxLng,
                    'vertices'       => GeoJsonPoint::vertexCount($geom),
                    'source'         => $full ? 'geoboundaries-full' : 'geoboundaries',
                    'source_release' => $release,
                    'licence'        => mb_substr($licence, 0, 80),
                    'loaded_at'      => now(),
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                $stored++;
            }
        });

        $this->seedAliases($iso3, $level);

        Log::info('Boundaries loaded', ['iso3' => $iso3, 'level' => $level, 'areas' => $stored, 'release' => $release]);

        return $stored;
    }

    /** The state whose polygon contains a point inside this area, else null. */
    private function stateContaining(array $geom, array $states, float $cLat, float $cLng): ?string
    {
        $candidates = [[$cLat, $cLng]];

        // A few vertices as fallbacks, spread through the ring.
        $type = $geom['type'] ?? '';
        $ring = $type === 'Polygon' ? ($geom['coordinates'][0] ?? []) : ($geom['coordinates'][0][0] ?? []);
        $n = count($ring);

        for ($i = 0; $i < 6 && $n > 0; $i++) {
            $pt = $ring[(int) floor($i * $n / 6)];
            $candidates[] = [(float) $pt[1], (float) $pt[0]];
        }

        foreach ($candidates as [$lat, $lng]) {
            foreach ($states as $s) {
                if ($lat >= $s['min_lat'] && $lat <= $s['max_lat'] && $lng >= $s['min_lng'] && $lng <= $s['max_lng']
                    && GeoJsonPoint::inside($lat, $lng, $s['geometry'])) {
                    return $s['code'];
                }
            }
        }

        // Nothing contains it: an island, or a sliver the simplified state
        // outline does not reach. The nearest state within reach claims it -
        // fifty kilometres covers every offshore district met so far.
        $best = null; $bestKm = 50.0;

        foreach ($states as $s) {
            $d = GeoJsonPoint::distanceToEdgeKm($cLat, $cLng, $s['geometry'], $bestKm);

            if ($d !== null && $d < $bestKm) {
                $bestKm = $d; $best = $s['code'];
            }
        }

        return $best;
    }

    /**
     * District NAMES as aliases of their state - no polygons, nothing shown.
     *
     * The owner does not want districts as a level, and is right: nobody
     * files news by daerah. But a story about the Jasin magistrate's court,
     * a Kuantan scam, a drowning off Kota Tinggi or a carnival in Bintulu
     * names a district and no state - and sixteen such stories in one day
     * were filed as national because nothing knew Kota Tinggi is in Johor.
     *
     * So the district set is read once, each district's state worked out from
     * the state polygons, and the NAME stored as an alias of that state. The
     * polygons are thrown away. "Kota Tinggi" then resolves to Johor exactly
     * as "Pulau Pinang" resolves to Penang, and the state level stays the
     * only level.
     */
    public function loadDistrictNames(string $iso3): ?int
    {
        $iso3 = strtoupper($iso3);

        $meta = Http::withHeaders(['User-Agent' => self::UA])->timeout(60)->get(self::API . "/{$iso3}/ADM2/");

        if ($meta->status() === 404) {
            return null;
        }

        $url = $meta->json('simplifiedGeometryGeoJSON') ?: $meta->json('gjDownloadURL');
        $geo = json_decode(Http::withHeaders(['User-Agent' => self::UA])->timeout(180)->get($url)->body(), true);

        if (!is_array($geo) || empty($geo['features'])) {
            throw new \RuntimeException("geoBoundaries {$iso3} ADM2: unreadable");
        }

        $states = DB::table('boundaries')->where('iso3', $iso3)->where('level', 1)->get()
            ->map(fn ($r) => ['code' => $r->code, 'min_lat' => (float) $r->min_lat, 'max_lat' => (float) $r->max_lat,
                              'min_lng' => (float) $r->min_lng, 'max_lng' => (float) $r->max_lng, 'geometry' => json_decode($r->geometry, true)])->all();

        if ($states === []) {
            throw new \RuntimeException("{$iso3}: load level 1 first");
        }

        $stored = 0;

        foreach ($geo['features'] as $f) {
            $name = trim((string) ($f['properties']['shapeName'] ?? ''));
            $geom = $f['geometry'] ?? null;

            if ($name === '' || !is_array($geom)) {
                continue;
            }

            [$minLat, $maxLat, $minLng, $maxLng] = GeoJsonPoint::bbox($geom);
            $state = $this->stateContaining($geom, $states, ($minLat + $maxLat) / 2, ($minLng + $maxLng) / 2);

            if ($state === null) {
                continue;
            }

            // A district that shares its name with a state ("Labuan",
            // "Melaka Tengah" is fine) must not shadow the state's own alias.
            $key = self::key($name);

            if (DB::table('boundaries')->where('iso3', $iso3)->where('level', 1)->where('name_key', $key)->exists()) {
                continue;
            }

            DB::table('boundary_aliases')->updateOrInsert(
                ['iso3' => $iso3, 'level' => 1, 'alias_key' => $key],
                ['code' => $state, 'alias' => mb_substr($name, 0, 160), 'language' => 'district',
                 'created_at' => now(), 'updated_at' => now()]
            );

            $stored++;
        }

        Log::info('District names loaded as state aliases', ['iso3' => $iso3, 'names' => $stored]);

        return $stored;
    }

    /** Country outline plus states, where the source has them. */
    public function loadCountry(string $iso3, bool $full = false): array
    {
        return [0 => $this->load($iso3, 0, $full), 1 => $this->load($iso3, 1, $full)];
    }

    /**
     * Other names for the same area, per country. The source names areas in
     * English; the stories name them in whatever language the paper writes.
     * A country's list is added here once and applies to every story after.
     */
    private const ALIASES = [
        'MYS' => [
            1 => [
                'Malacca'         => ['Melaka', 'Negeri Melaka', 'ms' => true],
                'Penang'          => ['Pulau Pinang', 'P. Pinang', 'Pinang', 'Negeri Pulau Pinang', '槟城'],
                'Kuala Lumpur'    => ['KL', 'W.P. Kuala Lumpur', 'WP Kuala Lumpur', 'Wilayah Persekutuan Kuala Lumpur', 'Federal Territory of Kuala Lumpur', '吉隆坡'],
                'Putrajaya'       => ['W.P. Putrajaya', 'Wilayah Persekutuan Putrajaya', '布城'],
                'Labuan'          => ['W.P. Labuan', 'Wilayah Persekutuan Labuan', '纳闽'],
                'Negeri Sembilan' => ['N. Sembilan', 'N9', '森美兰'],
                'Johor'           => ['Johore', '柔佛'],
                'Kedah'           => ['吉打'],
                'Kelantan'        => ['吉兰丹'],
                'Pahang'          => ['彭亨'],
                'Perak'           => ['霹雳'],
                'Perlis'          => ['玻璃市'],
                'Sabah'           => ['沙巴'],
                'Sarawak'         => ['砂拉越', '砂朥越'],
                'Selangor'        => ['雪兰莪'],
                'Terengganu'      => ['Trengganu', '登嘉楼'],
            ],
        ],
        'SGP' => [0 => ['Singapore' => ['Singapura', '新加坡']]],
        'BRN' => [0 => ['Brunei' => ['Negara Brunei Darussalam', 'Brunei Darussalam', '汶莱', '文莱']]],
        'IDN' => [0 => ['Indonesia' => ['印尼', '印度尼西亚']]],
        'THA' => [0 => ['Thailand' => ['Siam', '泰国']]],
        'CZE' => [0 => ['Czech Republic' => ['Czechia', 'Republik Czech', 'Republik Ceko', '捷克']]],
        'CHN' => [
            0 => ['China' => ['People\'s Republic of China', 'Republik Rakyat China', 'Cina', '中国', '中华人民共和国']],
            1 => [
                'Guangdong'                                => ['Guangdong Province', 'Guangdong Sheng', '广东', '广东省'],
                'Beijing Municipality'                     => ['Beijing', '北京', '北京市'],
                'Shanghai Municipality'                    => ['Shanghai', '上海', '上海市'],
                'Tianjin Municipality'                     => ['Tianjin', '天津'],
                'Chongqing Municipality'                   => ['Chongqing', '重庆'],
                'Fujian Province'                          => ['Fujian', '福建'],
                'Zhejiang Province'                        => ['Zhejiang', '浙江'],
                'Jiangsu Province'                         => ['Jiangsu', '江苏'],
                'Shandong Province'                        => ['Shandong', '山东'],
                'Sichuan Province'                         => ['Sichuan', '四川'],
                'Yunnan Province'                          => ['Yunnan', '云南'],
                'Hainan Province'                          => ['Hainan', '海南'],
                'Hubei Province'                           => ['Hubei', '湖北'],
                'Hunan Province'                           => ['Hunan', '湖南'],
                'Henan Province'                           => ['Henan', '河南'],
                'Hebei Province'                           => ['Hebei', '河北'],
                'Shaanxi Province'                         => ['Shaanxi', '陕西'],
                'Shanxi Province'                          => ['Shanxi', '山西'],
                'Guangxi Zhuang Autonomous Region'         => ['Guangxi', '广西'],
                'Tibet Autonomous Region'                  => ['Tibet', 'Xizang', '西藏'],
                'Xinjiang Uyghur Autonomous Region'        => ['Xinjiang', '新疆'],
                'Inner Mongolia Autonomous Region'         => ['Inner Mongolia', 'Nei Mongol', '内蒙古'],
                'Hong Kong Special Administrative Region'  => ['Hong Kong', '香港'],
                'Macau Special Administrative Region'      => ['Macau', 'Macao', '澳门'],
                'Taiwan Province'                          => ['Taiwan', '台湾'],
            ],
        ],
    ];

    /**
     * Names the source gets wrong, corrected on load. The one met so far: it
     * labels Guangdong province "Guangzhou Province" - Guangzhou is its
     * capital city. A reader in Foshan would be told they were in Guangzhou.
     */
    private const RENAMES = [
        'CHN' => [
            1 => [
                'Guangzhou Province'                      => 'Guangdong',
                'Ningxia Ningxia Hui Autonomous Region'   => 'Ningxia',
            ],
        ],
    ];

    private function seedAliases(string $iso3, int $level): void
    {
        $set = self::ALIASES[$iso3][$level] ?? [];

        foreach ($set as $canonical => $aliases) {
            $row = DB::table('boundaries')
                ->where('iso3', $iso3)->where('level', $level)
                ->where('name_key', self::key($canonical))
                ->first(['code']);

            if (!$row) {
                continue;
            }

            foreach ($aliases as $k => $alias) {
                if (!is_string($alias)) {
                    continue;
                }

                DB::table('boundary_aliases')->updateOrInsert(
                    ['iso3' => $iso3, 'level' => $level, 'alias_key' => self::key($alias)],
                    ['code' => $row->code, 'alias' => mb_substr($alias, 0, 160), 'language' => null,
                     'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public static function key(string $name): string
    {
        $k = mb_strtolower(trim($name));
        $k = preg_replace('/\s+/u', ' ', $k);

        return trim((string) $k, " .");
    }
}
