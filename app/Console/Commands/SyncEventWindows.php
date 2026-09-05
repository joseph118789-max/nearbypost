<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Stamp real event dates onto stories that came from an events API.
 *
 * ⛔ WHY THIS IS A SEPARATE PASS RATHER THAN PART OF THE FETCH.
 *
 * Fetched items are POSTed to an ingest endpoint that validates strictly, and
 * anything it does not know about is dropped. Adding event fields to that
 * payload means changing the contract on both sides - and this project has
 * already been bitten three times by a whitelist quietly discarding a field
 * nobody noticed was missing ($fillable, parseAiResponse, the ingest schema).
 * So the dates are applied afterwards, by URL, against rows that already exist.
 * Nothing can be silently lost: either the row is found and stamped, or this
 * says it was not.
 *
 * ⭐⭐ AND THE DATES ARE READ, NOT PARSED. The Events Calendar API gives
 * start_date and end_date as fields. Everywhere else on this project an event
 * window has to be recovered from prose, and prose lies: MyTOWN KL shows
 * "27 Aug 2026 to 6 Sep 2026" - how long the mall features the item - on a
 * festival that runs on the 5th and 6th. The owner spotted that. An API has
 * nothing to misread.
 */
class SyncEventWindows extends Command
{
    protected $signature = 'events:sync-windows
        {--source=      : One source name; default is every events_api source}
        {--dry-run      : Report what would change, write nothing}';

    protected $description = 'Read start and end dates from events APIs and stamp them on the stories';

    public function handle(): int
    {
        $sources = DB::table('sources')
            ->where('source_kind', 'events_api')
            ->where('is_active', true)
            ->when($this->option('source'), fn ($q) => $q->where('name', $this->option('source')))
            ->get(['id', 'name', 'index_url']);

        if ($sources->isEmpty()) {
            $this->warn('No active events_api sources.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        foreach ($sources as $source) {
            $windows = $this->windowsFor($source);

            if ($windows === []) {
                $this->warn(sprintf('  %-24s no events came back', $source->name));
                continue;
            }

            $stamped = $missing = 0;

            foreach ($windows as $url => $w) {
                $row = DB::table('news_items')->where('url', $url)->first(['id', 'event_start', 'event_end']);

                if ($row === null) {
                    $missing++;
                    continue;
                }

                // Already correct: leave it, and do not touch updated_at.
                if ((string) $row->event_start === (string) $w['start']
                    && (string) $row->event_end === (string) $w['end']) {
                    continue;
                }

                if (!$dry) {
                    DB::table('news_items')->where('id', $row->id)->update([
                        'event_start'      => $w['start'],
                        'event_end'        => $w['end'],
                        'event_open_ended' => false,
                        'updated_at'       => now(),
                    ]);

                    // The served copy has its own columns; a window that is not
                    // carried through is a window the reader never sees.
                    DB::table('feed_ready_items')->where('news_item_id', $row->id)->update([
                        'event_start'      => $w['start'],
                        'event_end'        => $w['end'],
                        'event_open_ended' => false,
                        'updated_at'       => now(),
                    ]);
                }

                $stamped++;
            }

            $this->line(sprintf('  %-24s %d event%s, %s%d stamped, %d not yet ingested',
                $source->name, count($windows), count($windows) === 1 ? '' : 's',
                $dry ? 'would be ' : '', $stamped, $missing));
        }

        return self::SUCCESS;
    }

    /**
     * url => ['start' => Y-m-d, 'end' => Y-m-d]
     *
     * @return array<string, array{start: ?string, end: ?string}>
     */
    private function windowsFor(object $source): array
    {
        $base = rtrim((string) $source->index_url, '?&');
        $out  = [];
        $page = 1;

        while ($page <= 20) {
            $url = $base . (str_contains($base, '?') ? '&' : '?') . 'per_page=50&page=' . $page;

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)',
                ])->timeout(30)->get($url);
            } catch (\Throwable $e) {
                break;
            }

            if (!$response->successful()) {
                break;
            }

            $body   = (array) $response->json();
            $events = $body['events'] ?? [];

            if ($events === []) {
                break;
            }

            foreach ($events as $event) {
                $link = (string) ($event['url'] ?? '');

                if ($link === '' || empty($event['start_date'])) {
                    continue;
                }

                $out[$link] = [
                    'start' => date('Y-m-d', strtotime((string) $event['start_date'])),
                    'end'   => !empty($event['end_date'])
                        ? date('Y-m-d', strtotime((string) $event['end_date']))
                        : date('Y-m-d', strtotime((string) $event['start_date'])),
                ];
            }

            if ($page >= (int) ($body['total_pages'] ?? 1)) {
                break;
            }

            $page++;
        }

        return $out;
    }
}
