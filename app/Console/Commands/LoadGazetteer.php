<?php

namespace App\Console\Commands;

use App\Services\Geo\Boundaries\BoundaryStore;
use App\Services\Geo\GazetteerSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fill the gazetteer.
 *
 *   gazetteer:load geonames              MY, SG, BN dumps from geonames.org
 *   gazetteer:load wikidata              every item with a coordinate in MY/SG/BN
 *   gazetteer:load csv --file=... --source=overture   a CSV a Python script wrote
 *   gazetteer:load stamp                 state codes from our polygons (chunked)
 *   gazetteer:load status                counts per source
 *
 * Every loader upserts on (source, source_id), so re-running is safe.
 */
class LoadGazetteer extends Command
{
    protected $signature = 'gazetteer:load {what : geonames|wikidata|csv|stamp|status} {--file=} {--source=} {--limit=0}';

    protected $description = 'Load open gazetteer data (GeoNames, Wikidata, Overture, Foursquare, WOF, gov lists) into the gazetteer table';

    private const COUNTRIES = ['MY' => 'MYS', 'SG' => 'SGP', 'BN' => 'BRN'];

    public function handle(): int
    {
        return match ($this->argument('what')) {
            'geonames' => $this->geonames(),
            'wikidata' => $this->wikidata(),
            'csv'      => $this->csv(),
            'stamp'    => $this->stamp(),
            'status'   => $this->status(),
            default    => (int) $this->error('unknown loader'),
        };
    }

    // ── GeoNames ───────────────────────────────────────────────────────────

    private function geonames(): int
    {
        $dir = storage_path('app/gazetteer');
        $total = 0;

        foreach (self::COUNTRIES as $iso2 => $iso3) {
            $zip = "{$dir}/{$iso2}.zip";
            $txt = "{$dir}/{$iso2}.txt";

            if (!file_exists($txt)) {
                $this->line("downloading GeoNames {$iso2}");
                file_put_contents($zip, Http::timeout(120)->get("https://download.geonames.org/export/dump/{$iso2}.zip")->body());
                $z = new \ZipArchive();

                if ($z->open($zip) !== true) {
                    $this->error("cannot open {$zip}");
                    continue;
                }

                $z->extractTo($dir);
                $z->close();
            }

            $rows = [];
            $n = 0;
            $fh = fopen($txt, 'r');

            while (($f = fgetcsv($fh, 0, "\t", '"', '')) !== false) {
                if (count($f) < 15) {
                    continue;
                }

                // geonameid, name, asciiname, alternatenames, lat, lng, fclass, fcode, cc, cc2, admin1..4, population
                $rows[] = [
                    'source'     => 'geonames',
                    'source_id'  => $f[0],
                    'name'       => mb_substr($f[1], 0, 250),
                    'name_key'   => GazetteerSearch::key($f[1]),
                    'alt_names'  => $f[3] !== '' ? mb_substr(str_replace(',', ' | ', $f[3]), 0, 4000) : null,
                    'kind'       => $f[6] . '.' . $f[7],
                    'category'   => self::GEONAMES_CODES[$f[7]] ?? $f[7],
                    'lat'        => (float) $f[4],
                    'lng'        => (float) $f[5],
                    'country'    => $iso3,
                    'admin_text' => null,
                    'population' => (int) $f[14] ?: null,
                    'raw'        => null,
                ];

                if (count($rows) === 1000) {
                    $n += $this->upsert($rows);
                    $rows = [];
                }
            }

            fclose($fh);
            $n += $this->upsert($rows);
            $this->line("  {$iso2}: {$n} rows");
            $total += $n;
        }

        $this->info("geonames: {$total} rows");

        return self::SUCCESS;
    }

    private const GEONAMES_CODES = [
        'PPL' => 'populated place', 'PPLA' => 'state capital', 'PPLA2' => 'district seat', 'PPLC' => 'capital', 'PPLX' => 'section of populated place',
        'HSP' => 'hospital', 'SCH' => 'school', 'SCHC' => 'college', 'UNIV' => 'university', 'MSQE' => 'mosque', 'CH' => 'church', 'TMPL' => 'temple',
        'STDM' => 'stadium', 'AIRP' => 'airport', 'PRT' => 'port', 'RSTN' => 'railway station', 'BDG' => 'bridge', 'DAM' => 'dam', 'MKT' => 'market',
        'PO' => 'post office', 'PS' => 'police station', 'ADM1' => 'state', 'ADM2' => 'district', 'ADM3' => 'sub-district', 'EST' => 'estate',
        'ISL' => 'island', 'BCH' => 'beach', 'MT' => 'mountain', 'HLL' => 'hill', 'STM' => 'stream', 'LK' => 'lake', 'FLLS' => 'waterfall',
        'PRK' => 'park', 'RES' => 'reserve', 'RESF' => 'forest reserve', 'CMTY' => 'cemetery', 'RSRT' => 'resort', 'HTL' => 'hotel', 'MALL' => 'mall',
    ];

    // ── Wikidata ───────────────────────────────────────────────────────────

    private function wikidata(): int
    {
        $total = 0;

        // One-degree longitude bands: the query service times out on the
        // whole country at once. Each band asks for items IN the country
        // with a coordinate INSIDE the band's box.
        foreach (['Q833' => 'MYS', 'Q334' => 'SGP', 'Q921' => 'BRN'] as $q => $iso3) {
            [$west, $east, $south, $north] = $iso3 === 'MYS' ? [99.0, 120.0, 0.8, 7.6] : ($iso3 === 'SGP' ? [103.5, 104.2, 1.1, 1.6] : [114.0, 115.5, 4.0, 5.1]);

            for ($lng = $west; $lng < $east; $lng += 1.0) {
                $sparql = sprintf(<<<'SPARQL'
                    SELECT ?item ?itemLabel ?itemAltLabel ?coord ?typeLabel WHERE {
                      SERVICE wikibase:box {
                        ?item wdt:P625 ?coord .
                        bd:serviceParam wikibase:cornerSouthWest "Point(%F %F)"^^geo:wktLiteral ;
                                        wikibase:cornerNorthEast "Point(%F %F)"^^geo:wktLiteral .
                      }
                      ?item wdt:P17 wd:%s .
                      OPTIONAL { ?item wdt:P31 ?type . }
                      SERVICE wikibase:label { bd:serviceParam wikibase:language "en,ms,zh". }
                    } LIMIT 20000
                    SPARQL, $lng, $south, min($lng + 1.0, $east), $north, $q);

                try {
                    $r = Http::withHeaders(['User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)', 'Accept' => 'application/sparql-results+json'])
                        ->timeout(120)->get('https://query.wikidata.org/sparql', ['query' => $sparql]);
                } catch (\Throwable $x) {
                    $this->warn(sprintf('  band %.0f: %s', $lng, mb_substr($x->getMessage(), 0, 80)));
                    continue;
                }

                if (!$r->successful()) {
                    $this->warn(sprintf('  band %.0f: HTTP %d', $lng, $r->status()));
                    sleep(5);
                    continue;
                }

                $rows = [];

                foreach ($r->json('results.bindings') ?: [] as $b) {
                    if (!preg_match('/Point\(([-\d.]+) ([-\d.]+)\)/', $b['coord']['value'] ?? '', $m)) {
                        continue;
                    }

                    $id = basename($b['item']['value']);
                    $label = $b['itemLabel']['value'] ?? $id;

                    if ($label === $id) {
                        continue;   // no label in any language we read
                    }

                    $rows[$id] = [
                        'source'     => 'wikidata',
                        'source_id'  => $id,
                        'name'       => mb_substr($label, 0, 250),
                        'name_key'   => GazetteerSearch::key($label),
                        'alt_names'  => isset($b['itemAltLabel']['value']) ? mb_substr(str_replace(', ', ' | ', $b['itemAltLabel']['value']), 0, 4000) : null,
                        'kind'       => 'wd',
                        'category'   => isset($b['typeLabel']['value']) ? mb_substr($b['typeLabel']['value'], 0, 160) : null,
                        'lat'        => (float) $m[2],
                        'lng'        => (float) $m[1],
                        'country'    => $iso3,
                        'admin_text' => null,
                        'population' => null,
                        'raw'        => null,
                    ];
                }

                $n = 0;
                foreach (array_chunk(array_values($rows), 1000) as $chunk) {
                    $n += $this->upsert($chunk);
                }

                $this->line(sprintf('  %s band %.0f: %d items', $iso3, $lng, $n));
                $total += $n;
                sleep(2);
            }
        }

        $this->info("wikidata: {$total} rows");

        return self::SUCCESS;
    }

    // ── CSV from a Python script (Overture, Foursquare, WOF, gov) ──────────

    private function csv(): int
    {
        $file = (string) $this->option('file');
        $source = (string) $this->option('source');

        if (!is_file($file) || $source === '') {
            $this->error('need --file=path.csv --source=name');

            return self::FAILURE;
        }

        $fh = fopen($file, 'r');
        $head = fgetcsv($fh);
        $idx = array_flip($head);

        foreach (['source_id', 'name', 'lat', 'lng'] as $need) {
            if (!isset($idx[$need])) {
                $this->error("csv lacks column {$need}");

                return self::FAILURE;
            }
        }

        $rows = [];
        $n = 0;
        $limit = (int) $this->option('limit');

        while (($f = fgetcsv($fh)) !== false) {
            $g = fn ($k) => isset($idx[$k]) ? ($f[$idx[$k]] ?? null) : null;
            $name = trim((string) $g('name'));

            if ($name === '' || !is_numeric($g('lat')) || !is_numeric($g('lng'))) {
                continue;
            }

            $rows[] = [
                'source'     => $source,
                'source_id'  => mb_substr((string) $g('source_id'), 0, 80),
                'name'       => mb_substr($name, 0, 250),
                'name_key'   => GazetteerSearch::key($name),
                'alt_names'  => ($a = trim((string) $g('alt_names'))) !== '' ? mb_substr($a, 0, 4000) : null,
                'kind'       => ($k = trim((string) $g('kind'))) !== '' ? mb_substr($k, 0, 64) : null,
                'category'   => ($c = trim((string) $g('category'))) !== '' ? mb_substr($c, 0, 160) : null,
                'lat'        => (float) $g('lat'),
                'lng'        => (float) $g('lng'),
                'country'    => ($cc = strtoupper(trim((string) $g('country')))) !== '' ? (self::COUNTRIES[$cc] ?? (strlen($cc) === 3 ? $cc : null)) : null,
                'admin_text' => ($t = trim((string) $g('admin_text'))) !== '' ? mb_substr($t, 0, 250) : null,
                'population' => is_numeric($g('population')) ? (int) $g('population') : null,
                'raw'        => null,
            ];

            if (count($rows) === 1000) {
                $n += $this->upsert($rows);
                $rows = [];

                if ($limit && $n >= $limit) {
                    break;
                }
            }
        }

        $n += $this->upsert($rows);
        fclose($fh);
        $this->info("{$source}: {$n} rows from " . basename($file));

        return self::SUCCESS;
    }

    // ── State codes from our own polygons ──────────────────────────────────

    private function stamp(): int
    {
        $store = new BoundaryStore();
        $limit = (int) $this->option('limit') ?: 0;
        $done = 0;

        while (true) {
            $rows = DB::table('gazetteer')->whereNull('stamped_at')->orderBy('id')->limit(500)->get(['id', 'lat', 'lng']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $r) {
                $at = $store->locate((float) $r->lat, (float) $r->lng);
                DB::table('gazetteer')->where('id', $r->id)->update([
                    'country'    => $at['country'] ?? DB::raw('country'),
                    'state_code' => $at['state'] ?? null,
                    'stamped_at' => now(),
                ]);
                $done++;
            }

            $this->line("  stamped {$done}");

            if ($limit && $done >= $limit) {
                break;
            }
        }

        $this->info("stamp: {$done} rows");

        return self::SUCCESS;
    }

    private function status(): int
    {
        foreach (DB::select("select source, count(*) n, count(state_code) stamped, count(*) filter (where country = 'MYS') my from gazetteer group by source order by n desc") as $r) {
            $this->line(sprintf('  %-12s %8d rows  %8d with state  %8d in Malaysia', $r->source, $r->n, $r->stamped, $r->my));
        }

        return self::SUCCESS;
    }

    private function upsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table('gazetteer')->upsert($rows, ['source', 'source_id'], ['name', 'name_key', 'alt_names', 'kind', 'category', 'lat', 'lng', 'country', 'admin_text', 'population']);

        return count($rows);
    }
}
