<?php

namespace App\Console\Commands;

use App\Services\Geo\Boundaries\GeoJsonPoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stamp country and state on gazetteer rows from our own polygons - fast.
 *
 * The general locate() streams each polygon from JSON text per call, which
 * is right for a web request and hopeless for a million rows. Here the
 * state polygons of the countries we hold data for are decoded ONCE into
 * memory (CLI has no memory limit) and every row is tested against the
 * ones whose box contains it. Coastal rows a little outside every polygon
 * get the nearest state within 3 km.
 */
class StampGazetteer extends Command
{
    protected $signature = 'gazetteer:stamp {--countries=MYS,SGP,BRN} {--chunk=2000}';

    protected $description = 'Stamp state_code on unstamped gazetteer rows using in-memory state polygons';

    private const COASTAL_KM = 3.0;

    public function handle(): int
    {
        $countries = array_filter(array_map('trim', explode(',', (string) $this->option('countries'))));
        $states = [];

        foreach (DB::table('boundaries')->whereIn('iso3', $countries)->where('level', 1)->orderBy('id')->get(['iso3', 'code', 'name', 'geometry', 'min_lat', 'max_lat', 'min_lng', 'max_lng']) as $b) {
            $geometry = is_string($b->geometry) ? json_decode($b->geometry, true) : (array) $b->geometry;

            if (!is_array($geometry) || $geometry === []) {
                continue;
            }

            $states[] = ['iso3' => $b->iso3, 'code' => $b->code, 'name' => $b->name, 'geometry' => $geometry,
                'min_lat' => (float) $b->min_lat, 'max_lat' => (float) $b->max_lat, 'min_lng' => (float) $b->min_lng, 'max_lng' => (float) $b->max_lng];
        }

        $this->line(sprintf('%d state polygons in memory (%d MB)', count($states), memory_get_usage(true) / 1048576));

        $chunk = (int) $this->option('chunk');
        $done = 0;
        $coastal = 0;
        $none = 0;
        $started = microtime(true);

        while (true) {
            $rows = DB::table('gazetteer')->whereNull('stamped_at')->orderBy('id')->limit($chunk)->get(['id', 'lat', 'lng', 'country']);

            if ($rows->isEmpty()) {
                break;
            }

            $updates = [];   // state code => [ids]
            $countryOf = []; // state code => iso3
            $unplaced = [];

            foreach ($rows as $r) {
                $lat = (float) $r->lat;
                $lng = (float) $r->lng;
                $hit = null;

                foreach ($states as $s) {
                    if ($lat < $s['min_lat'] || $lat > $s['max_lat'] || $lng < $s['min_lng'] || $lng > $s['max_lng']) {
                        continue;
                    }

                    if (GeoJsonPoint::inside($lat, $lng, $s['geometry'])) {
                        $hit = $s;
                        break;
                    }
                }

                if ($hit === null) {
                    // Just off the coast? The nearest state within reach.
                    $best = null;
                    $bestKm = self::COASTAL_KM;

                    foreach ($states as $s) {
                        if ($lat < $s['min_lat'] - 0.05 || $lat > $s['max_lat'] + 0.05 || $lng < $s['min_lng'] - 0.05 || $lng > $s['max_lng'] + 0.05) {
                            continue;
                        }

                        $km = GeoJsonPoint::distanceToEdgeKm($lat, $lng, $s['geometry'], $bestKm);

                        if ($km !== null && $km < $bestKm) {
                            $bestKm = $km;
                            $best = $s;
                        }
                    }

                    if ($best !== null) {
                        $hit = $best;
                        $coastal++;
                    }
                }

                if ($hit === null) {
                    $unplaced[] = $r->id;
                    $none++;
                    continue;
                }

                $updates[$hit['code']][] = $r->id;
                $countryOf[$hit['code']] = $hit['iso3'];
            }

            foreach ($updates as $code => $ids) {
                DB::table('gazetteer')->whereIn('id', $ids)->update(['state_code' => $code, 'country' => $countryOf[$code], 'stamped_at' => now()]);
            }

            if ($unplaced !== []) {
                DB::table('gazetteer')->whereIn('id', $unplaced)->update(['stamped_at' => now()]);
            }

            $done += $rows->count();

            if ($done % 20000 === 0 || $rows->count() < $chunk) {
                $this->line(sprintf('  stamped %d  (%d coastal, %d outside every polygon)  %.0f rows/s', $done, $coastal, $none, $done / max(0.001, microtime(true) - $started)));
            }
        }

        $this->info(sprintf('stamp: %d rows in %.0f s', $done, microtime(true) - $started));

        return self::SUCCESS;
    }
}
