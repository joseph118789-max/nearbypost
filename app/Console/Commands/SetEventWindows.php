<?php

namespace App\Console\Commands;

use App\Services\Ingest\DateWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read the run-dates out of a promotion's headline.
 *
 * A separate stage rather than a hook inside the fetcher, for two reasons. It
 * is idempotent, so it can be re-run over everything after the parser learns a
 * new phrasing; and it touches only the sources marked as event sources, so a
 * news headline that happens to contain a date range - "the trial runs 16-20
 * September" - is never given a window it should not have.
 *
 * Run: php artisan ingest:event-windows
 */
class SetEventWindows extends Command
{
    protected $signature = 'ingest:event-windows
        {--all : Re-read every story, not only the ones without a window}
        {--dry-run : Report what would be set and change nothing}';

    protected $description = 'Set the start and end dates of promotions and events from their headlines';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $sources = DB::table('sources')
            ->where('is_event_source', true)
            ->pluck('event_open_days', 'name');

        if ($sources->isEmpty()) {
            $this->info('No sources are marked as event sources.');

            return 0;
        }

        $query = DB::table('news_items')
            ->whereIn('source', $sources->keys())
            ->orderByDesc('id');

        if (!$this->option('all')) {
            $query->whereNull('event_start')->whereNull('event_end');
        }

        $stories = $query->limit(500)->get(['id', 'title', 'source', 'published_at']);

        $set = 0;
        $missed = [];

        foreach ($stories as $story) {
            $window = DateWindow::parse((string) $story->title);

            if ($window['start'] === null) {
                $missed[] = $story->title;

                continue;
            }

            $end = $window['end'];

            // "Onwards" is not forever. Nobody comes back to say a promotion
            // finished, so an open run is given the source's own allowance and
            // flagged, rather than sitting on the site until someone notices.
            if ($end === null && $window['open']) {
                $days = (int) ($sources[$story->source] ?? 30);
                $end = date('Y-m-d', strtotime($window['start'] . " +{$days} days"));
            }

            $this->line(sprintf('  %-6d %s → %s%s  %s',
                $story->id,
                $window['start'],
                $end ?? '(none)',
                $window['open'] ? ' (open-ended)' : '',
                mb_substr((string) $story->title, 0, 52)
            ));

            $set++;

            if ($dry) {
                continue;
            }

            DB::table('news_items')->where('id', $story->id)->update([
                'event_start'      => $window['start'],
                'event_end'        => $end,
                'event_open_ended' => $window['open'],
                'updated_at'       => now(),
            ]);

            // The served copy carries its own dates; leaving it behind is how a
            // finished sale stays on the site after the story has been updated.
            DB::table('feed_ready_items')->where('news_item_id', $story->id)->update([
                'event_start'      => $window['start'],
                'event_end'        => $end,
                'event_open_ended' => $window['open'],
            ]);
        }

        // Whatever set a window - this parser, or an extractor that was handed
        // the dates by the publisher - the SERVED copy has to carry it, or the
        // feed cannot keep the story while it runs. Done as one statement over
        // everything rather than trusting each writer to remember.
        if (!$dry) {
            $synced = DB::update("
                UPDATE feed_ready_items f
                SET event_start = n.event_start,
                    event_end = n.event_end,
                    event_open_ended = n.event_open_ended
                FROM news_items n
                WHERE f.news_item_id = n.id
                  AND n.event_end IS NOT NULL
                  AND (f.event_end IS DISTINCT FROM n.event_end
                       OR f.event_start IS DISTINCT FROM n.event_start)
            ");

            if ($synced > 0) {
                $this->line(sprintf('  %d served rows brought into step', $synced));
            }
        }

        $this->newLine();
        $this->info(sprintf('%s a window on %d of %d.%s',
            $dry ? 'Would set' : 'Set',
            $set,
            $stories->count(),
            $missed === [] ? '' : ' ' . count($missed) . ' had no readable dates.'
        ));

        // Said out loud, because a headline this parser cannot read is how the
        // next phrasing gets found - and a promotion with no window quietly
        // behaves like ordinary news.
        foreach (array_slice($missed, 0, 8) as $title) {
            $this->line('    no dates: ' . mb_substr($title, 0, 66));
        }

        return 0;
    }
}
