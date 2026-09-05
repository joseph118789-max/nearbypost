<?php

namespace App\Console\Commands;

use App\Services\Geo\Boundaries\BoundaryLoader;
use App\Services\Geo\Boundaries\BoundaryStore;
use App\Services\Geo\Boundaries\Iso3166;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load boundaries from geoBoundaries.
 *
 *   boundaries:load --country=MYS            outline and states for one country
 *   boundaries:load --country=MYS --level=1  one level only
 *   boundaries:load --world                  every country's outline (level 0)
 *   boundaries:load --status                 what is loaded
 *
 * Countries are also fetched on demand the first time a story names them, so
 * --world is a convenience, not a prerequisite: it means the first story from
 * Uruguay is not the one that pays for the download.
 */
class LoadBoundaries extends Command
{
    protected $signature = 'boundaries:load
        {--country= : ISO3 code, or several separated by commas}
        {--level= : 0 or 1; both when omitted}
        {--world : Every country outline (level 0)}
        {--full : Full-resolution geometry (the home country; simplified elsewhere)}
        {--district-names : Teach district NAMES as aliases of their state (no polygons, no level)}
        {--status : Show what is loaded and stop}';

    protected $description = 'Load country and state boundaries (geoBoundaries, ODbL)';

    public function handle(BoundaryLoader $loader): int
    {
        if ($this->option('status')) {
            return $this->status();
        }

        $levels = $this->option('level') !== null ? [(int) $this->option('level')] : [0, 1];

        if ($this->option('world')) {
            $level = $this->option('level') !== null ? (int) $this->option('level') : 0;
            $this->info("Loading level {$level} for every country. About 230 downloads; a few minutes.");
            $done = 0; $missing = []; $failed = [];

            foreach (Iso3166::all() as $iso3) {
                try {
                    $n = $loader->load($iso3, $level);

                    if ($n === null) {
                        $missing[] = $iso3;
                    } else {
                        $done++;
                        $this->line(sprintf('  %s  %-32s %s', $iso3, Iso3166::name($iso3), $n === 1 ? '' : "({$n} parts)"));
                    }
                } catch (\Throwable $e) {
                    $failed[] = $iso3;
                    $this->warn("  {$iso3}  " . $e->getMessage());
                }
            }

            BoundaryStore::forget();
            $this->newLine();
            $this->info("Loaded {$done} countries.");

            if ($missing) {
                $this->line('Not in the source: ' . implode(', ', $missing));
            }

            if ($failed) {
                $this->warn('Failed (run again later): ' . implode(', ', $failed));
            }

            return 0;
        }

        $countries = array_filter(array_map('trim', explode(',', (string) $this->option('country'))));

        if ($this->option('district-names')) {
            foreach ($countries as $iso3) {
                $n = $loader->loadDistrictNames(strtoupper($iso3));
                $this->line(sprintf('  %s district names -> state aliases: %s', strtoupper($iso3), $n === null ? 'not in the source' : $n));
            }

            BoundaryStore::forget();

            return 0;
        }

        if ($countries === []) {
            $this->error('Give --country=ISO3 (e.g. MYS), --world, or --status.');

            return 1;
        }

        foreach ($countries as $iso3) {
            $iso3 = strtoupper($iso3);

            if (Iso3166::name($iso3) === null) {
                $this->warn("  {$iso3}: not an ISO 3166-1 alpha-3 code");
                continue;
            }

            foreach ($levels as $level) {
                try {
                    $n = $loader->load($iso3, $level, (bool) $this->option('full'));
                    $this->line(sprintf('  %s level %d  %s', $iso3, $level,
                        $n === null ? 'not in the source' : "{$n} area(s)"));
                } catch (\Throwable $e) {
                    $this->error("  {$iso3} level {$level}: " . $e->getMessage());
                }
            }
        }

        BoundaryStore::forget();

        return 0;
    }

    private function status(): int
    {
        $rows = DB::table('boundaries')
            ->selectRaw('iso3, level, count(*) as areas, sum(vertices) as vertices, max(source_release) as release, max(loaded_at) as loaded_at')
            ->groupBy('iso3', 'level')->orderBy('iso3')->orderBy('level')->get();

        $this->line(sprintf('  %-4s %-5s %6s %9s  %-9s %s', 'ISO3', 'level', 'areas', 'vertices', 'release', 'loaded'));

        foreach ($rows as $r) {
            $this->line(sprintf('  %-4s %-5d %6d %9d  %-9s %s', $r->iso3, $r->level, $r->areas, $r->vertices, $r->release, $r->loaded_at));
        }

        $t = DB::table('boundaries')->selectRaw('count(distinct iso3) as countries, count(*) as areas')->first();
        $this->info("{$t->countries} countries, {$t->areas} areas.");

        return 0;
    }
}
