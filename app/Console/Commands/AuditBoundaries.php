<?php

namespace App\Console\Commands;

use App\Services\Geo\Boundaries\BoundaryCheck;
use App\Services\Geo\Boundaries\BoundaryStore;
use App\Services\Geo\ClaimCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every pinned story, checked against the place its name claims.
 *
 *   boundaries:audit           report
 *   boundaries:audit --fix     unpin the wrong ones so the cascade retries
 *                              them under the new rule
 *   boundaries:audit --stamp   write country/state codes onto every pinned
 *                              story whose pin is fine
 *
 * Run once after loading, and again after any boundary reload.
 */
class AuditBoundaries extends Command
{
    protected $signature = 'boundaries:audit
        {--fix : Unpin stories whose pin is outside the place they name}
        {--stamp : Record the country and state code on every correctly pinned story}
        {--live : Only stories currently served (default: every pinned story)}';

    protected $description = 'Check every pinned story against the country and state its name claims';

    /** The only countries shown below state level. Add one here, deliberately. */
    public const DISTRICT_COUNTRIES = ['CHN'];

    public function handle(): int
    {
        $check = new BoundaryCheck(new BoundaryStore());
        $chain = new \App\Services\Geo\ChainCheck();

        $q = DB::table('news_items as n')
            ->whereNotNull('n.latitude')->whereNotNull('n.longitude')
            ->where('n.geocode_status', 'success')
            ->select('n.id', 'n.canonical_place_name', 'n.main_place_text', 'n.latitude', 'n.longitude', 'n.geocode_provider', 'n.title');

        if ($this->option('live')) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('feed_ready_items as f')
                ->whereColumn('f.news_item_id', 'n.id')->where('f.is_active', true));
        }

        $rows = $q->orderByDesc('n.id')->get();
        $this->info("Checking {$rows->count()} pinned stories.");

        $tally = ['inside' => 0, 'outside' => 0, 'unknown' => 0];
        $conflicts = 0;
        $wrong = [];
        $stamped = 0;

        foreach ($rows as $r) {
            $name = $r->canonical_place_name ?: $r->main_place_text;

            if (!$name) {
                continue;
            }

            $v = $check->verify($name, (float) $r->latitude, (float) $r->longitude);
            $tally[$v['verdict']]++;

            if ($v['verdict'] === 'outside') {
                $wrong[] = [$r, $v];
                $this->line(sprintf('  WRONG  #%-6d %-14s %-44s %s', $r->id, $r->geocode_provider ?? '-',
                    mb_substr($name, 0, 44), $v['detail']));
                continue;
            }

            // A pin can sit inside the country the text claims and still be the
            // wrong place on earth. "Shenzhen, Wilayah Persekutuan Kuala
            // Lumpur" passed every check here: the point really was in Kuala
            // Lumpur, and Kuala Lumpur really is in the country the masthead
            // claimed. What nobody asked was whether Malaysia contains any
            // place called Shenzhen. It does not.
            //
            // Treated exactly like an out-of-country pin, because it is the
            // same failure wearing a disguise: unpin it, and let the cascade
            // try again knowing which country the NAME implies.
            $c = $chain->verify($name, $v['actual']['country'] ?? null);

            if (!$c['ok']) {
                $conflicts++;
                $v['detail'] = $c['reason'];

                // Marked, because a chain conflict needs one thing an
                // out-of-country pin does not: see the fix block below.
                $v['chain_conflict'] = true;
                $wrong[] = [$r, $v];
                $this->line(sprintf('  CHAIN  #%-6d %-14s %-44s %s', $r->id, $r->geocode_provider ?? '-',
                    mb_substr($name, 0, 44), $c['reason']));

                continue;
            }

            if ($this->option('stamp') && $v['actual']['country']) {
                DB::table('news_items')->where('id', $r->id)->update([
                    'geo_country_code' => $v['actual']['country'],
                    'geo_state_code'   => $v['actual']['state'],
                    'geo_city_code'    => $v['actual']['city'] ?? null,
                ]);
                DB::table('feed_ready_items')->where('news_item_id', $r->id)->update([
                    'geo_country_code' => $v['actual']['country'],
                    'geo_state_code'   => $v['actual']['state'],
                    'geo_city_code'    => $v['actual']['city'] ?? null,
                ]);
                $stamped++;
            }
        }

        // Districts are an opt-in per country, never automatic. Malaysia had
        // them for an afternoon: technically correct, and useless - nobody,
        // newsrooms included, works in daerah, so a level nobody reads is a
        // level that only confuses. Very large countries are the exception,
        // and they are named here rather than inferred from story counts.
        if ($this->option('stamp')) {
            foreach (self::DISTRICT_COUNTRIES as $iso3) {
                if (!DB::table('boundaries')->where('iso3', $iso3)->where('level', 2)->exists()) {
                    try {
                        $areas = (new \App\Services\Geo\Boundaries\BoundaryLoader())->load($iso3, 2);
                        $this->line("  loaded districts for {$iso3}: " . ($areas ?? 'none in the source'));
                        BoundaryStore::forget();
                    } catch (\Throwable $e) {
                        $this->warn("  districts for {$iso3}: " . $e->getMessage());
                    }
                }
            }
        }

        // A state for the unpinned, from what already exists:
        //
        //  - a duplicate takes its survivor's state: it was never classified
        //    itself, but it is the same story, and the survivor was;
        //  - a place text that names a state, or a place list whose "where it
        //    happened" places sit in one state, gives that state;
        //  - places in two or more states give MULTI - a haze reading across
        //    Sarawak, Sabah and Selangor is not a Sarawak story and not a
        //    national one; it is a several-states story, and gets its own row.
        //
        // Measured on one day's 1,439 stateless Malaysian stories: 670 were
        // duplicates, 31 named a state in text, 7 spanned states. This is
        // what those numbers can honestly become.
        if ($this->option('stamp')) {
            $claimed = ['survivor' => 0, 'text' => 0, 'multi' => 0];
            $checkFor = new BoundaryCheck(new BoundaryStore());

            DB::table('news_items as n')
                ->whereNull('n.geo_state_code')->whereNull('n.geo_claim_state')
                ->where(fn ($q) => $q->whereNotNull('n.duplicate_of')->orWhereNotNull('n.main_place_text')->orWhereNotNull('n.place_roles'))
                ->select('n.id', 'n.duplicate_of', 'n.main_place_text', 'n.canonical_place_name', 'n.place_roles', 'n.source', 'n.geo_claim_country')
                ->orderBy('n.id')
                ->chunkById(500, function ($chunk) use (&$claimed, $checkFor) {
                    foreach ($chunk as $r) {
                        $state = null; $how = null;

                        if ($r->duplicate_of) {
                            $s = DB::table('news_items')->where('id', $r->duplicate_of)
                                ->selectRaw("coalesce(geo_state_code, geo_claim_state) as st, coalesce(geo_country_code, geo_claim_country) as co")->first();

                            // A duplicate IS its survivor's story: country and state
                            // both come from there, overriding the masthead default.
                            // Copying only the state put Indonesian and Nepali state
                            // codes under Malaysia, because the duplicate had kept the
                            // country its publisher implied.
                            if ($s && $s->co && $s->co !== ($r->geo_claim_country ?? $s->co)) {
                                DB::table('news_items')->where('id', $r->id)->update(['geo_claim_country' => $s->co]);
                            }

                            if ($s && $s->st) {
                                $state = $s->st; $how = 'survivor';
                            }
                        }

                        if ($state === null) {
                            $home = \App\Services\Geo\SourceCountry::iso2($r->source);
                            $iso3 = $r->geo_claim_country ?: \App\Services\Geo\Boundaries\Iso3166::iso3($home ?? 'MY');
                            $states = [];

                            $text = $r->canonical_place_name ?: $r->main_place_text;
                            if ($text) {
                                $e = $checkFor->stateOfKnownPlace($text, $home);
                                if ($e && $e['country'] === $iso3) $states[$e['state']] = true;
                            }

                            // Every place the model said the news happened at or
                            // is about. A town alone names no state, but the
                            // geocode cache has placed most of them before.
                            foreach (json_decode($r->place_roles ?? '[]', true) ?: [] as $p) {
                                if (!in_array($p['role'] ?? '', ['happened', 'subject', 'aftermath'], true)) continue;
                                $e = $checkFor->stateOfKnownPlace((string) ($p['p'] ?? ''), $home);
                                if ($e && $e['country'] === $iso3) $states[$e['state']] = true;
                            }

                            if (count($states) >= 2) { $state = 'MULTI'; $how = 'multi'; }
                            elseif (count($states) === 1) { $state = array_key_first($states); $how = 'text'; }
                        }

                        if ($state !== null) {
                            DB::table('news_items')->where('id', $r->id)->update(['geo_claim_state' => $state]);
                            $claimed[$how]++;
                        }
                    }
                });

            $this->line("  claimed a state for {$claimed['survivor']} duplicates (from their survivor), {$claimed['text']} from text, {$claimed['multi']} across several states");
        }

        // The unpinned stories still belong to a country - the one their
        // text or masthead gives. Filled for every story that lacks it, so
        // the Countries report can count all of them, discards included.
        if ($this->option('stamp')) {
            $claimed = 0;

            DB::table('news_items')
                ->whereNull('geo_claim_country')
                ->select('id', 'main_place_text', 'canonical_place_name', 'source', 'geo_country_code')
                ->orderBy('id')
                ->chunkById(500, function ($chunk) use (&$claimed) {
                    foreach ($chunk as $r) {
                        $iso3 = ClaimCountry::of($r->canonical_place_name ?: $r->main_place_text, $r->source, $r->geo_country_code);

                        if ($iso3 !== null) {
                            DB::table('news_items')->where('id', $r->id)->update(['geo_claim_country' => $iso3]);
                            $claimed++;
                        }
                    }
                });

            $this->line("  claimed a country for {$claimed} unpinned stories");
        }

        $this->newLine();
        $this->line(sprintf('  inside %d   outside %d   unknown %d', $tally['inside'], $tally['outside'], $tally['unknown']));

        if ($stamped) {
            $this->line("  stamped country/state codes on {$stamped} stories");
        }

        if ($wrong && $this->option('fix')) {
            foreach ($wrong as [$r, $v]) {
                $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*,\s*Malaysia\s*$/i', '', $r->canonical_place_name ?: $r->main_place_text))));

                // The cached answer is what put it there; forget it, or the
                // cascade will simply hand the same wrong point back.
                DB::table('geocode_cache')->where('place_key', $key)->delete();

                $reset = [
                    'latitude' => null, 'longitude' => null, 'lat' => null, 'lng' => null,
                    'geocode_status' => 'failed', 'geocode_provider' => null,
                    'precision_type' => null, 'coverage_status' => null,
                    'geo_country_code' => null, 'geo_state_code' => null,
                    'geocoded_at' => now(), 'updated_at' => now(),
                ];

                // A CHAIN CONFLICT POISONS ITS OWN INPUT, and unpinning alone
                // cannot escape it. canonical_place_name is not what the story
                // said - it is what the bad geocode WROTE BACK. "Shenzhen,
                // Wilayah Persekutuan Kuala Lumpur" now contains a Malaysian
                // state, so every retry reads that state, resolves on it, and
                // lands in Malaysia again however firmly the country is
                // steered. Measured: three retries, three Malaysian pins.
                //
                // Clearing it sends the geocoder back to main_place_text - the
                // model's own words, here the Chinese characters for Shenzhen -
                // which LatinName reads, ChainCheck recognises, and the cascade
                // then resolves to 22.556, 114.119. Correct.
                //
                // Only for chain conflicts. An ordinary out-of-country pin
                // usually has a perfectly good name and a wrong point.
                if ($v['chain_conflict'] ?? false) {
                    $reset['canonical_place_name'] = null;
                }

                DB::table('news_items')->where('id', $r->id)->update($reset);
                DB::table('feed_ready_items')->where('news_item_id', $r->id)->update([
                    'lat' => null, 'lng' => null, 'geo_country_code' => null, 'geo_state_code' => null, 'updated_at' => now(),
                ]);
            }

            $this->info('  unpinned ' . count($wrong) . ' - the geocoder will retry them under the boundary rule');

            if ($conflicts > 0) {
                $this->line("  of those, {$conflicts} named a place that does not exist in the country they were pinned to");
            }
        } elseif ($wrong) {
            $this->warn('  run with --fix to unpin these');
        }

        return 0;
    }
}
