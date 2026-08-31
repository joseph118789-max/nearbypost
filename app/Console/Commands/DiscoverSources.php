<?php

namespace App\Console\Commands;

use App\Services\FeedDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Learn what to crawl.
 *
 * The source list used to be whatever someone typed on the day it was written,
 * which capped coverage permanently. Whole sub-categories stayed empty as a
 * result: nine general news feeds carry almost no badminton, tennis or
 * automotive coverage, however well the classifier works on what they do carry.
 *
 * This command closes that loop. It runs in three stages:
 *
 *   1. SIGHTINGS  Aggregator results name the publisher behind every headline.
 *                 Each name and homepage is recorded, and a publisher that keeps
 *                 recurring is worth investigating; one seen once may be noise.
 *
 *   2. PROBE      For each candidate seen often enough, look for a feed: first
 *                 the ones the site declares in its own head, then conventional
 *                 paths. Anything found is fetched and parsed before it is
 *                 believed.
 *
 *   3. SECTIONS   For publishers already trusted, look for per-section feeds.
 *                 A sports section feed is what actually produces badminton and
 *                 football stories; the front page never will.
 *
 * What worked is written back to the source row, so the next run goes straight
 * to the URL instead of probing again. What failed is recorded too, so a dead
 * site is not re-probed every hour for ever.
 *
 * Discovered publishers are activated at the secondary tier. They are not
 * trusted blindly: a candidate must have been seen repeatedly and must serve a
 * feed that parses and carries items.
 */
class DiscoverSources extends Command
{
    protected $signature = 'ingest:discover
        {--probe-limit=8 : How many new candidates to probe this run}
        {--min-sightings=2 : Times a publisher must be seen before probing}
        {--sections : Also look for section feeds on trusted publishers}
        {--activate : Activate verified discoveries (otherwise they are only recorded)}
        {--dry-run : Report what would happen, change nothing}';

    protected $description = 'Discover new publishers and section feeds, and record how to crawl them';

    public function __construct(private FeedDiscovery $discovery)
    {
        parent::__construct();
    }

    /** Addresses an editor has told us never to visit again. */
    private \App\Services\SourceBlocklist $blocklist;

    public function handle(): int
    {
        $this->blocklist = new \App\Services\SourceBlocklist();

        $this->recordSightings();

        $probed = $this->probeCandidates();

        if ($this->option('sections')) {
            $probed += $this->probeSections();
        }

        $this->info("Done. probed={$probed}");

        return 0;
    }

    /**
     * Stage 1: note which publishers keep appearing behind aggregator headlines.
     */
    private function recordSightings(): void
    {
        $seen = cache()->pull('ingest:publisher_sightings', []);

        if ($seen === []) {
            $this->line('No new publisher sightings queued.');
            return;
        }

        $new = 0;

        foreach ($seen as $homepage => $info) {
            $existing = DB::table('sources')->where('base_url', $homepage)->first();

            if ($existing) {
                DB::table('sources')->where('id', $existing->id)->update([
                    'sightings'  => $existing->sightings + $info['count'],
                    'updated_at' => now(),
                ]);
                continue;
            }

            // An editor removed this and said never again. Discovery learning
            // it back from the next aggregator citation is the software
            // ignoring them.
            if ($this->blocklist->isBlocked($homepage)) {
                continue;
            }

            DB::table('sources')->insert([
                'name'                  => mb_substr($info['name'], 0, 120),
                'base_url'              => $homepage,
                'rss_url'               => null,
                'language'              => 'Unknown',
                'source_type'           => 'discovered',
                'direct_rss_supported'  => false,
                'google_news_supported' => true,
                'is_active'             => false,
                'priority_tier'         => 'parked',
                'discovery_status'      => 'candidate',
                'discovered_from'       => $info['via'] ?? 'aggregator',
                'sightings'             => $info['count'],
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            $new++;
        }

        $this->info("Sightings recorded. new candidates={$new}");
    }

    /**
     * Stage 2: probe candidates that have been seen often enough.
     */
    private function probeCandidates(): int
    {
        $candidates = DB::table('sources')
            ->where('discovery_status', 'candidate')
            ->where('sightings', '>=', (int) $this->option('min-sightings'))
            ->whereNotNull('base_url')
            ->where(function ($q) {
                // Never probed, or last probed long enough ago to be worth retrying.
                $q->whereNull('last_probed_at')
                  ->orWhere('last_probed_at', '<', now()->subDays(7));
            })
            ->orderByDesc('sightings')
            ->limit((int) $this->option('probe-limit'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('No candidates ready to probe.');
            return 0;
        }

        $this->info("Probing {$candidates->count()} candidate(s).");

        foreach ($candidates as $candidate) {
            $found = $this->discovery->discoverMain($candidate->base_url);

            if (!$found) {
                $this->updateRow($candidate->id, [
                    'discovery_status' => 'rejected',
                    'last_probed_at'   => now(),
                    'probe_notes'      => 'no parseable feed found',
                ]);
                $this->warn(sprintf('  %-28s no feed', $candidate->name));
                continue;
            }

            $activate = (bool) $this->option('activate');

            $this->updateRow($candidate->id, [
                'rss_url'              => $found['url'],
                'direct_rss_supported' => true,
                'discovery_status'     => 'verified',
                'is_active'            => $activate,
                'priority_tier'        => $activate ? 'secondary' : 'parked',
                'last_probed_at'       => now(),
                'probe_notes'          => "found via {$found['method']}, {$found['items']} items",
            ]);

            $this->line(sprintf(
                '  %-28s %s (%d items, %s)%s',
                $candidate->name,
                $found['url'],
                $found['items'],
                $found['method'],
                $activate ? ' ACTIVATED' : ''
            ));

            Log::info('Source discovered', [
                'name'   => $candidate->name,
                'feed'   => $found['url'],
                'method' => $found['method'],
            ]);
        }

        return $candidates->count();
    }

    /**
     * Stage 3: section feeds on publishers we already trust.
     *
     * This is what fills the sub-categories a front page never reaches.
     */
    private function probeSections(): int
    {
        $parents = DB::table('sources')
            ->where('is_active', true)
            ->whereNull('parent_source_id')
            ->whereNotNull('base_url')
            ->where('source_type', '!=', 'aggregator')
            ->where(function ($q) {
                $q->whereNull('last_probed_at')->orWhere('last_probed_at', '<', now()->subDays(30));
            })
            ->limit(4)
            ->get();

        if ($parents->isEmpty()) {
            $this->line('No publishers due a section probe.');
            return 0;
        }

        $added = 0;

        foreach ($parents as $parent) {
            $sections = $this->discovery->discoverSections($parent->base_url);

            foreach ($sections as $section => $found) {
                $exists = DB::table('sources')->where('rss_url', $found['url'])->exists();

                if ($exists || $this->blocklist->isBlocked($found['url'])) {
                    continue;
                }

                if (!$this->option('dry-run')) {
                    DB::table('sources')->insert([
                        'name'                  => $parent->name . ' - ' . ucfirst($section),
                        'base_url'              => $parent->base_url,
                        'rss_url'               => $found['url'],
                        'language'              => $parent->language,
                        'source_type'           => 'section',
                        'direct_rss_supported'  => true,
                        'google_news_supported' => false,
                        'is_active'             => (bool) $this->option('activate'),
                        'priority_tier'         => 'secondary',
                        'discovery_status'      => 'verified',
                        'discovered_from'       => 'section-probe:' . $parent->name,
                        'parent_source_id'      => $parent->id,
                        'section'               => $section,
                        'probe_notes'           => "found via {$found['method']}, {$found['items']} items",
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);
                }

                $this->line(sprintf('  %-34s %s (%d items)', $parent->name . '/' . $section, $found['url'], $found['items']));
                $added++;
            }

            $this->updateRow($parent->id, ['last_probed_at' => now()]);
        }

        $this->info("Section feeds added: {$added}");

        return $added;
    }

    private function updateRow(int $id, array $data): void
    {
        if ($this->option('dry-run')) {
            return;
        }

        DB::table('sources')->where('id', $id)->update($data + ['updated_at' => now()]);
    }
}
