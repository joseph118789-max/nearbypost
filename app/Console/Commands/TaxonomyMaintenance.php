<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keep the stored sub-category names honest, and rename one safely.
 *
 * Renaming "Motorsports" to "Racing" meant editing four tables by hand, because
 * each held the word rather than a reference to it. Nothing enforced agreement,
 * and the table most easily forgotten was the bench - which would then have
 * marked the model wrong for giving the answer we had just asked it for.
 *
 * The id is now the truth and the name is a copy. This command keeps that true:
 *
 *   sync    fills in missing ids by matching the name, then refreshes any name
 *           that has drifted from the shelf its id points at
 *   rename  changes the shelf once, then syncs - which propagates the new name
 *           everywhere it is stored, by id, without anybody listing the tables
 *
 * The name is still read by the public pages, the API and the feed filters, so
 * it must stay accurate. It simply is not what anything means any more.
 */
class TaxonomyMaintenance extends Command
{
    protected $signature = 'taxonomy:sub
        {action=sync    : sync, rename, or check}
        {--from=        : rename: the shelf as it is now}
        {--to=          : rename: what it should be called}
        {--dry-run      : report, change nothing}';

    protected $description = 'Keep stored sub-category names in step with the taxonomy, and rename one safely';

    /** Where a sub-category name is stored, and the id column beside it. */
    private const STORES = [
        ['table' => 'news_items',       'name' => 'sub_category', 'id' => 'sub_category_id'],
        ['table' => 'feed_ready_items', 'name' => 'sub_category', 'id' => 'sub_category_id'],
        ['table' => 'bench_items',      'name' => 'expect_sub',   'id' => 'expect_sub_id'],
    ];

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'rename' => $this->rename(),
            'check'  => $this->check(),
            default  => $this->sync(),
        };
    }

    /**
     * Fill in missing ids, then correct any name that disagrees with its id.
     *
     * Order matters: a row with no id is matched by name, which is the only
     * handle it has. Once it has an id, the id wins for ever after.
     */
    private function sync(): int
    {
        $dry = (bool) $this->option('dry-run');
        $shelves = DB::table('subcategories')->get(['id', 'sub_category']);

        $linked = $corrected = 0;

        foreach (self::STORES as $store) {
            foreach ($shelves as $shelf) {
                // 1. A row that holds this name but no id: give it one.
                $q = DB::table($store['table'])
                    ->whereNull($store['id'])
                    ->where($store['name'], $shelf->sub_category);

                $n = $dry ? $q->count() : $q->update([$store['id'] => $shelf->id]);
                $linked += $n;
            }

            // 2. A row whose id points at a shelf with a different name: the
            //    shelf is right and the copy is stale.
            $stale = DB::table($store['table'] . ' as t')
                ->join('subcategories as s', 's.id', '=', 't.' . $store['id'])
                ->whereColumn('t.' . $store['name'], '!=', 's.sub_category')
                ->count();

            if ($stale > 0 && !$dry) {
                DB::statement(
                    "update {$store['table']} t set {$store['name']} = s.sub_category
                     from subcategories s
                     where s.id = t.{$store['id']} and t.{$store['name']} is distinct from s.sub_category"
                );
            }

            $corrected += $stale;

            $this->line(sprintf('  %-18s linked and checked', $store['table']));
        }

        $this->info(sprintf(
            '%s %d rows linked to a shelf, %d stale names corrected.',
            $dry ? 'Would have' : 'Done:',
            $linked,
            $corrected
        ));

        return 0;
    }

    /**
     * Rename a shelf, then let sync carry the new name everywhere.
     *
     * The point of doing it here rather than by hand is that nobody has to
     * remember which tables hold a copy. They are listed once, above.
     */
    private function rename(): int
    {
        $from = (string) $this->option('from');
        $to   = (string) $this->option('to');

        if ($from === '' || $to === '') {
            $this->error('Give both --from and --to.');

            return 1;
        }

        $shelf = DB::table('subcategories')->where('sub_category', $from)->first();

        if (!$shelf) {
            $this->error("No sub-category called \"{$from}\".");

            return 1;
        }

        if (DB::table('subcategories')
            ->where('primary_category', $shelf->primary_category)
            ->where('sub_category', $to)
            ->exists()) {
            $this->error("\"{$to}\" already exists under {$shelf->primary_category}. Merging is a different job.");

            return 1;
        }

        if ($this->option('dry-run')) {
            $this->info("Would rename {$shelf->primary_category} / {$from} -> {$to}, then sync.");

            return 0;
        }

        DB::table('subcategories')->where('id', $shelf->id)
            ->update(['sub_category' => $to, 'updated_at' => now()]);

        $this->info("Renamed {$shelf->primary_category} / {$from} -> {$to}");

        Log::info('Sub-category renamed', ['id' => $shelf->id, 'from' => $from, 'to' => $to]);

        return $this->sync();
    }

    /** What disagrees right now, without changing anything. */
    private function check(): int
    {
        $problems = 0;

        foreach (self::STORES as $store) {
            $unlinked = DB::table($store['table'])
                ->whereNull($store['id'])
                ->whereNotNull($store['name'])
                ->where($store['name'], '!=', '')
                ->count();

            $stale = DB::table($store['table'] . ' as t')
                ->join('subcategories as s', 's.id', '=', 't.' . $store['id'])
                ->whereColumn('t.' . $store['name'], '!=', 's.sub_category')
                ->count();

            $problems += $unlinked + $stale;

            $this->line(sprintf(
                '  %-18s %4d with a name but no shelf, %4d whose name has drifted',
                $store['table'],
                $unlinked,
                $stale
            ));
        }

        $problems === 0
            ? $this->info('Everything agrees.')
            : $this->warn("{$problems} rows need a sync.");

        return 0;
    }
}
