<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingestion staleness alarm.
 *
 * The ingestion engine has stopped without anyone noticing more than once: the
 * scheduled n8n workflow failed on 1,351 consecutive runs across at least a
 * fortnight while the site carried on serving older articles, so nothing looked
 * broken from the outside.
 *
 * This check is deliberately engine-agnostic. It does not care whether n8n, a
 * cron script, or something else is collecting news; it only asks whether fresh
 * articles are still arriving, and whether the stages behind ingestion are
 * keeping up. That way it keeps working through any future change of engine.
 *
 * Exit code 1 when unhealthy, so cron mail or any external monitor can act on it.
 */
class IngestionHealthCheck extends Command
{
    protected $signature = 'ingest:health
        {--max-age=6 : Hours without a new article before ingestion is considered stalled}
        {--quiet-ok  : Print nothing when everything is healthy}';

    protected $description = 'Alarm when news ingestion, enrichment or geocoding has stalled';

    public function handle(): int
    {
        $maxAgeHours = max(1, (int) $this->option('max-age'));
        $problems    = [];
        $stats       = [];

        // ── 1. Is anything still arriving? ──────────────────────────────
        $newest = NewsItem::max('created_at');
        $ageHours = $newest ? (int) round(abs(now()->diffInHours($newest))) : null;
        $stats['newest_article_age_hours'] = $ageHours;

        if ($newest === null) {
            $problems[] = 'No articles in the database at all.';
        } elseif ($ageHours >= $maxAgeHours) {
            $problems[] = sprintf(
                'Ingestion stalled: newest article is %dh old (limit %dh).',
                $ageHours,
                $maxAgeHours
            );
        }

        // ── 2. Is enrichment keeping up with ingestion? ─────────────────
        $pending = NewsItem::where('ai_status', 'pending')
            ->where('created_at', '<', now()->subHours(2))
            ->count();
        $stats['enrichment_backlog'] = $pending;

        if ($pending > 200) {
            $problems[] = "Enrichment backlog: {$pending} articles pending for over 2h.";
        }

        // ── 3. Are placed articles still reaching the map? ───────────────
        $recentPlaced = NewsItem::where('created_at', '>', now()->subDay())
            ->where('relevance_mode', '!=', 'category_only')
            ->whereNotNull('main_place_text')
            ->count();

        $recentGeocoded = NewsItem::where('created_at', '>', now()->subDay())
            ->where('geocode_status', 'success')
            ->count();

        $stats['placed_24h']   = $recentPlaced;
        $stats['geocoded_24h'] = $recentGeocoded;

        if ($recentPlaced >= 20 && $recentGeocoded === 0) {
            $problems[] = "Geocoding stalled: {$recentPlaced} placed articles in 24h, none geocoded.";
        }

        // ── 4. Is the serving layer current? ─────────────────────────────
        $feedNewest = DB::table('feed_ready_items')->max('published_at');
        $feedAge    = $feedNewest ? (int) round(abs(now()->diffInHours($feedNewest))) : null;
        $stats['feed_newest_age_hours'] = $feedAge;

        if ($feedAge !== null && $feedAge >= ($maxAgeHours * 4)) {
            $problems[] = "Serving layer stale: newest feed item is {$feedAge}h old.";
        }

        // ── Report ───────────────────────────────────────────────────────
        // ── 5. Is a story being served by a row we call a duplicate? ────
        //
        // pickKeeper used to choose a keeper without knowing whether it could
        // actually be served, so the ONE copy that reached readers could end up
        // marked as the duplicate. The story still shows exactly once, which is
        // why this went unnoticed - but any future tidy-up of duplicate rows
        // would take it off the site. Fixed forward in DedupeStories; this
        // counts what is left so the number shrinks rather than quietly grows.
        $misKeyed = DB::table('news_items as d')
            ->join('news_items as s', 's.id', '=', 'd.duplicate_of')
            ->join('feed_ready_items as f', function ($j) {
                $j->on('f.news_item_id', '=', 'd.id')->where('f.is_active', true);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('feed_ready_items as sf')
                  ->whereColumn('sf.news_item_id', 's.id')->where('sf.is_active', true);
            })
            ->count();

        $stats['served_rows_marked_duplicate'] = $misKeyed;

        // Not an alarm at today's number - nothing is wrong for a reader. It
        // becomes one if it starts climbing again, which would mean the keeper
        // fix has been undone.
        if ($misKeyed > 60) {
            $problems[] = "{$misKeyed} served stories are marked as duplicates of something unserved.";
        }

        if ($problems === []) {
            if (!$this->option('quiet-ok')) {
                $this->info('Ingestion healthy. ' . json_encode($stats));
            }

            return 0;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        Log::error('Ingestion health check failed', [
            'problems' => $problems,
            'stats'    => $stats,
        ]);

        return 1;
    }
}
